<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Read-only bank API recheck status for CRM. Never signals payment acceptance. */
final class CheckoutCodPrivatRefetchStatusService
{
    /** @return array<string,mixed> */
    public function inspect(string $kind, string $sourceKey): array
    {
        $this->authorize();
        if (!in_array($kind, ['single', 'batch'], true) || !Str::isUuid($sourceKey)) {
            throw new InvalidArgumentException('Invalid refetch status source');
        }
        $table = $kind === 'single'
            ? 'checkout_cod_settlement_bank_receipts' : 'checkout_cod_bank_batches';
        $source = DB::table($table)->where('operation_key', strtolower($sourceKey))
            ->first(['id', 'reference_hmac', 'claimed_net_minor']);
        if ($source === null) {
            throw new DomainException('COD bank source not found');
        }
        $last = DB::table('checkout_cod_privat_refetches')
            ->where('source_kind', $kind)->where('source_id', $source->id)
            ->orderByDesc('id')->first();
        $local = DB::table('checkout_cod_privat_statement_entries')
            ->where('reference_hmac', $source->reference_hmac)
            ->get(['id', 'transaction_hmac', 'row_hmac', 'amount_minor', 'direction']);
        $sourceChanged = $last !== null && (
                (string) $last->reference_hmac !== (string) $source->reference_hmac
                || (int) $last->amount_minor !== (int) $source->claimed_net_minor
                || $local->count() !== 1
                || (int) $local->first()->id !== (int) $last->statement_entry_id
                || (string) $local->first()->transaction_hmac !== (string) $last->transaction_hmac
                || (string) $local->first()->row_hmac !== (string) $last->row_hmac
                || $local->first()->direction !== 'credit'
            );
        $hold = (bool) (int) DB::selectOne(
            'SELECT CASE WHEN boardriders_cod_financial_has_hold(CAST(? AS TEXT), CAST(? AS BIGINT)) '
            . 'THEN 1 ELSE 0 END AS held', [$kind, $source->id]
        )->held;
        $recent = $last !== null && !$sourceChanged
            && !$hold && (string) $last->result_code === 'consistent_unverified'
            && \Illuminate\Support\Carbon::parse($last->created_at)->greaterThanOrEqualTo(now()->subMinutes(15));
        $status = match (true) {
            $last === null => 'awaiting_fresh_api_read',
            $sourceChanged => 'local_statement_changed',
            $hold => 'financial_investigation_hold',
            !$recent && $last->result_code === 'consistent_unverified' => 'fresh_api_read_expired',
            default => (string) $last->result_code,
        };
        return [
            'source_kind' => $kind,
            'source_id' => (int) $source->id,
            'refetch_id' => $last === null ? null : (int) $last->id,
            'status' => $status,
            'refetch_result_code' => $last?->result_code,
            'fresh_consistent_api_read' => $recent,
            'local_statement_current' => !$sourceChanged && $local->count() === 1,
            'financial_hold' => $hold,
            'last_refetched_at' => $last?->created_at,
            'bank_native_signature_verified' => false,
            'funds_received_verified' => false,
            'payment_posting_allowed' => false,
            'cod_paid' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function recent(int $limit = 25, ?int $beforeId = null): array
    {
        $this->authorize();
        if ($limit < 1 || $limit > 100 || ($beforeId !== null && $beforeId < 1)) {
            throw new InvalidArgumentException('Invalid refetch status cursor');
        }
        $q = DB::table('checkout_cod_privat_refetches')->orderByDesc('id');
        if ($beforeId !== null) {
            $q->where('id', '<', $beforeId);
        }
        $rows = $q->limit($limit + 1)->get([
            'id', 'source_kind', 'source_id', 'result_code', 'reference_rows',
            'identity_rows', 'exact_rows', 'created_at',
        ]);
        $more = $rows->count() > $limit;
        $items = $rows->take($limit)->map(static fn (object $r): array => [
            'refetch_id' => (int) $r->id,
            'source_kind' => (string) $r->source_kind,
            'source_id' => (int) $r->source_id,
            'result_code' => (string) $r->result_code,
            'reference_rows' => (int) $r->reference_rows,
            'identity_rows' => (int) $r->identity_rows,
            'exact_rows' => (int) $r->exact_rows,
            'refetched_at' => (string) $r->created_at,
            'payment_posting_allowed' => false,
        ])->values()->all();
        return [
            'items' => $items,
            'next_before_id' => $more && $items !== []
                ? $items[count($items) - 1]['refetch_id'] : null,
            'automatic_payment_posting_allowed' => false,
        ];
    }

    private function authorize(): void
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0 || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )) {
            throw new AuthorizationException('Bank refetch read permission denied');
        }
    }
}
