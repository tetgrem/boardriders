<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Append-only investigation workflow.
 * No money moves and no financial holds are released.
 */
final class CheckoutCodFinancialResolutionService
{
    public const EVIDENCE_ABILITY = 'cod.financial-resolution.evidence';
    public const PROPOSE_ABILITY = 'cod.financial-resolution.propose';
    public const REVIEW_ABILITY = 'cod.financial-resolution.review';

    public function __construct(
        private readonly CheckoutCodFinancialResolutionPolicy $policy
    ) {
    }

    /** @return array<string,mixed> */
    public function addEvidence(
        string $exceptionKey,
        string $operationKey,
        string $kind,
        string $documentSha256
    ): array {
        $actor = $this->actor(self::EVIDENCE_ABILITY);

        $this->uuids($exceptionKey, $operationKey);
        $this->policy->evidence($kind, $documentSha256);

        $exceptionKey = strtolower($exceptionKey);
        $operationKey = strtolower($operationKey);

        return DB::transaction(function () use (
            $actor,
            $exceptionKey,
            $operationKey,
            $kind,
            $documentSha256
        ): array {
            $incident = $this->incident($exceptionKey);

            $signature = $this->hmac([
                'cod-resolution-evidence-v1',
                $exceptionKey,
                $operationKey,
                (int) $incident->id,
                $actor,
                $kind,
                $documentSha256,
            ]);

            $old = DB::table('checkout_cod_exception_evidence')
                ->where('operation_key', $operationKey)
                ->first();

            if ($old !== null) {
                $this->replay($old, $signature);
                return $this->presentEvidence($old);
            }

            $author = $this->sourceAuthor($incident);

            if (in_array($actor, [
                (int) $incident->reported_by,
                (int) $incident->initial_reviewer,
                $author,
            ], true)) {
                throw new DomainException(
                    'Evidence collector is not independent'
                );
            }

            $latest = $this->latestProposal((int) $incident->id);

            if (
                $latest !== null
                && $latest->decision !== 'return_for_rework'
            ) {
                throw new DomainException(
                    'Evidence frozen during proposal review'
                );
            }

            if (
                DB::table('checkout_cod_exception_evidence')
                    ->where('exception_id', $incident->id)
                    ->where('document_sha256', $documentSha256)
                    ->exists()
            ) {
                throw new DomainException(
                    'Evidence digest already registered'
                );
            }

            if (
                DB::table('checkout_cod_exception_evidence')
                    ->where('exception_id', $incident->id)
                    ->count() >= 100
            ) {
                throw new DomainException(
                    'Evidence limit reached'
                );
            }

            $id = DB::table('checkout_cod_exception_evidence')
                ->insertGetId([
                    'operation_key' => $operationKey,
                    'exception_id' => $incident->id,
                    'evidence_kind' => $kind,
                    'document_sha256' => $documentSha256,
                    'recorded_by' => $actor,
                    'request_hmac' => $signature,
                    'created_at' => now(),
                ]);

            return $this->presentEvidence(
                DB::table('checkout_cod_exception_evidence')
                    ->where('id', $id)
                    ->first()
            );
        }, 3);
    }

    /** @return array<string,mixed> */
    public function propose(
        string $exceptionKey,
        string $operationKey,
        string $recommendationKind,
        int $recommendedNetMinor
    ): array {
        $actor = $this->actor(self::PROPOSE_ABILITY);

        $this->uuids($exceptionKey, $operationKey);

        $exceptionKey = strtolower($exceptionKey);
        $operationKey = strtolower($operationKey);

        return DB::transaction(function () use (
            $actor,
            $exceptionKey,
            $operationKey,
            $recommendationKind,
            $recommendedNetMinor
        ): array {
            $incident = $this->incident($exceptionKey);
            $source = $this->source($incident);

            $this->policy->proposal(
                (string) $incident->source_kind,
                (string) $incident->reason_code,
                $recommendationKind,
                (int) $source->claimed_net_minor,
                $recommendedNetMinor
            );

            $old = DB::table('checkout_cod_exception_proposals')
                ->where('operation_key', $operationKey)
                ->first();

            // Retries use the evidence count pinned at initial creation.
            $stats = DB::table('checkout_cod_exception_evidence')
                ->where('exception_id', $incident->id)
                ->selectRaw(
                    'COUNT(*) AS n, ' .
                    'COUNT(DISTINCT recorded_by) AS authors, ' .
                    'COUNT(DISTINCT evidence_kind) AS kinds'
                )
                ->first();

            $count = $old === null
                ? (int) $stats->n
                : (int) $old->evidence_count;

            $signature = $this->hmac([
                'cod-resolution-proposal-v1',
                $exceptionKey,
                $operationKey,
                (int) $incident->id,
                $actor,
                $recommendationKind,
                (int) $source->claimed_net_minor,
                $recommendedNetMinor,
                $count,
            ]);

            if ($old !== null) {
                $this->replay($old, $signature);
                return $this->presentProposal($old);
            }

            $latest = $this->latestProposal((int) $incident->id);

            if (
                $latest !== null
                && $latest->decision !== 'return_for_rework'
            ) {
                throw new DomainException(
                    'Correction proposal already active'
                );
            }

            if (
                $count < 2
                || $count > 100
                || (int) $stats->authors < 2
                || (int) $stats->kinds < 2
            ) {
                throw new DomainException(
                    'Two independent evidence collectors and kinds required'
                );
            }

            if (
                in_array($actor, [
                    (int) $incident->reported_by,
                    (int) $incident->initial_reviewer,
                    (int) $source->recorded_by,
                ], true)
                || DB::table('checkout_cod_exception_evidence')
                    ->where('exception_id', $incident->id)
                    ->where('recorded_by', $actor)
                    ->exists()
            ) {
                throw new DomainException(
                    'Correction proposer is not independent'
                );
            }

            $id = DB::table('checkout_cod_exception_proposals')
                ->insertGetId([
                    'operation_key' => $operationKey,
                    'exception_id' => $incident->id,
                    'recommendation_kind' => $recommendationKind,
                    'source_net_minor' => $source->claimed_net_minor,
                    'recommended_net_minor' => $recommendedNetMinor,
                    'delta_minor' =>
                        $recommendedNetMinor - (int) $source->claimed_net_minor,
                    'evidence_count' => $count,
                    'proposed_by' => $actor,
                    'request_hmac' => $signature,
                    'created_at' => now(),
                ]);

            return $this->presentProposal(
                DB::table('checkout_cod_exception_proposals')
                    ->where('id', $id)
                    ->first()
            );
        }, 3);
    }

    /** @return array<string,mixed> */
    public function review(
        string $proposalKey,
        string $operationKey,
        string $decision,
        string $reasonCode
    ): array {
        $actor = $this->actor(self::REVIEW_ABILITY);

        $this->uuids($proposalKey, $operationKey);
        $this->policy->review($decision, $reasonCode);

        $proposalKey = strtolower($proposalKey);
        $operationKey = strtolower($operationKey);

        return DB::transaction(function () use (
            $actor,
            $proposalKey,
            $operationKey,
            $decision,
            $reasonCode
        ): array {
            $proposal = DB::table('checkout_cod_exception_proposals')
                ->where('operation_key', $proposalKey)
                ->first();

            if ($proposal === null) {
                throw new DomainException(
                    'Correction proposal not found'
                );
            }

            $incident = $this->incidentById(
                (int) $proposal->exception_id
            );

            $source = $this->source($incident);

            $signature = $this->hmac([
                'cod-resolution-review-v1',
                $proposalKey,
                $operationKey,
                (int) $proposal->id,
                $actor,
                $decision,
                $reasonCode,
                (string) $proposal->request_hmac,
            ]);

            $old = DB::table('checkout_cod_exception_proposal_reviews')
                ->where('proposal_id', $proposal->id)
                ->first();

            if ($old !== null) {
                if (
                    (string) $old->operation_key !== $operationKey
                ) {
                    throw new DomainException(
                        'Correction proposal already reviewed'
                    );
                }

                $this->replay($old, $signature);
                return $this->presentReview($old);
            }

            if (
                DB::table('checkout_cod_exception_proposal_reviews')
                    ->where('operation_key', $operationKey)
                    ->exists()
            ) {
                throw new DomainException(
                    'Correction review UUID reused'
                );
            }

            $latest = $this->latestProposal(
                (int) $incident->id
            );

            if (
                $latest === null
                || (int) $latest->id !== (int) $proposal->id
            ) {
                throw new DomainException(
                    'Only latest correction proposal is reviewable'
                );
            }

            if (
                in_array($actor, [
                    (int) $incident->reported_by,
                    (int) $incident->initial_reviewer,
                    (int) $source->recorded_by,
                    (int) $proposal->proposed_by,
                ], true)
                || DB::table('checkout_cod_exception_evidence')
                    ->where('exception_id', $incident->id)
                    ->where('recorded_by', $actor)
                    ->exists()
            ) {
                throw new DomainException(
                    'Correction reviewer is not independent'
                );
            }

            $id = DB::table('checkout_cod_exception_proposal_reviews')
                ->insertGetId([
                    'operation_key' => $operationKey,
                    'proposal_id' => $proposal->id,
                    'decision' => $decision,
                    'reason_code' => $reasonCode,
                    'reviewed_by' => $actor,
                    'request_hmac' => $signature,
                    'created_at' => now(),
                ]);

            return $this->presentReview(
                DB::table('checkout_cod_exception_proposal_reviews')
                    ->where('id', $id)
                    ->first()
            );
        }, 3);
    }

    private function incident(string $key): object
    {
        $row = DB::table('checkout_cod_financial_exceptions')
            ->where('exception_key', $key)
            ->first(['id']);

        if ($row === null) {
            throw new DomainException(
                'Financial exception not found'
            );
        }

        return $this->incidentById((int) $row->id);
    }

    private function incidentById(int $id): object
    {
        DB::selectOne(
            'SELECT boardriders_cod_resolution_lock_exception(' .
            'CAST(? AS BIGINT)) AS locked_source',
            [$id]
        );

        $incident = DB::table('checkout_cod_financial_exceptions as e')
            ->join(
                'checkout_cod_financial_exception_reviews as r',
                'r.exception_id',
                '=',
                'e.id'
            )
            ->where('e.id', $id)
            ->where('r.decision', 'hold_for_investigation')
            ->first([
                'e.id',
                'e.source_kind',
                'e.source_id',
                'e.reason_code',
                'e.reported_by',
                'r.reviewed_by as initial_reviewer',
            ]);

        if ($incident === null) {
            throw new DomainException(
                'Independent investigation hold required'
            );
        }

        return $incident;
    }

    private function source(object $incident): object
    {
        $table = match ($incident->source_kind) {
            'single' => 'checkout_cod_settlement_bank_receipts',
            'batch' => 'checkout_cod_bank_batches',
            'novapay' => 'checkout_cod_settlement_observations',
            default => throw new DomainException(
                'Unknown exception source'
            ),
        };

        $row = DB::table($table)
            ->where('id', $incident->source_id)
            ->first([
                'recorded_by',
                'claimed_net_minor',
            ]);

        if ($row === null) {
            throw new DomainException(
                'Original financial claim missing'
            );
        }

        return $row;
    }

    private function sourceAuthor(object $incident): int
    {
        return (int) $this->source($incident)->recorded_by;
    }

    private function latestProposal(int $exceptionId): ?object
    {
        return DB::table('checkout_cod_exception_proposals as p')
            ->leftJoin(
                'checkout_cod_exception_proposal_reviews as r',
                'r.proposal_id',
                '=',
                'p.id'
            )
            ->where('p.exception_id', $exceptionId)
            ->orderByDesc('p.id')
            ->first([
                'p.id',
                'r.decision',
            ]);
    }

    private function actor(string $ability): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            (!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows($ability)
        ) {
            throw new AuthorizationException(
                'Financial resolution permission denied'
            );
        }

        return (int) $id;
    }

    private function uuids(string $a, string $b): void
    {
        if (!Str::isUuid($a) || !Str::isUuid($b)) {
            throw new InvalidArgumentException(
                'Invalid financial resolution UUID'
            );
        }
    }

    private function hmac(array $data): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }

        return hash_hmac(
            'sha256',
            json_encode($data, JSON_THROW_ON_ERROR),
            $key
        );
    }

    private function replay(object $row, string $signature): void
    {
        if (!hash_equals(
            (string) $row->request_hmac,
            $signature
        )) {
            throw new DomainException(
                'Financial resolution UUID reused'
            );
        }
    }

    private function presentEvidence(object $r): array
    {
        return [
            'id' => (int) $r->id,
            'operation_key' => (string) $r->operation_key,
            'exception_id' => (int) $r->exception_id,
            'evidence_kind' => (string) $r->evidence_kind,
            'recorded_by' => (int) $r->recorded_by,
            'external_evidence_authenticated' => false,
            'refund_executed' => false,
        ];
    }

    private function presentProposal(object $r): array
    {
        return [
            'id' => (int) $r->id,
            'operation_key' => (string) $r->operation_key,
            'exception_id' => (int) $r->exception_id,
            'recommendation_kind' =>
                (string) $r->recommendation_kind,
            'source_net_minor' => (int) $r->source_net_minor,
            'recommended_net_minor' =>
                (int) $r->recommended_net_minor,
            'delta_minor' => (int) $r->delta_minor,
            'evidence_count' => (int) $r->evidence_count,
            'proposed_by' => (int) $r->proposed_by,
            'financial_hold_released' => false,
            'funds_received_verified' => false,
            'refund_executed' => false,
        ];
    }

    private function presentReview(object $r): array
    {
        return [
            'id' => (int) $r->id,
            'operation_key' => (string) $r->operation_key,
            'proposal_id' => (int) $r->proposal_id,
            'decision' => (string) $r->decision,
            'reason_code' => (string) $r->reason_code,
            'financial_hold_released' => false,
            'funds_received_verified' => false,
            'refund_executed' => false,
        ];
    }
}
