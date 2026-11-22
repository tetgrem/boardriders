<?php

declare(strict_types=1);

namespace App\Services\Fiscal62;

use App\Jobs\Fiscal62SendJob;
use App\Services\Fiscal61\Fiscal61AuditService;
use App\Services\Fiscal61\Fiscal61SourceService;
use App\Services\Pricing\CheckoutOrderReturnSigner;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class Fiscal62TicketService
{
    public function __construct(
        private readonly Fiscal62Access $access,
        private readonly Fiscal61AuditService $drafts,
        private readonly Fiscal61SourceService $sources,
        private readonly Fiscal62Envelope $envelope,
        private readonly Fiscal62Integrity $audit,
        private readonly CheckoutOrderReturnSigner $signer,
        private readonly Fiscal62CheckboxClient $checkbox,
    ) {}

    public function prepare(int $documentId): array
    {
        $actor = $this->access->actor(
            Fiscal62Access::PREPARE
        );

        $this->enabled();

        if ($documentId <= 0) {
            throw new DomainException(
                'Invalid fiscal draft ID'
            );
        }

        return DB::transaction(function () use (
            $documentId,
            $actor
        ): array {
            $locator = DB::table(
                'checkout_fiscal_documents61'
            )
                ->where('id', $documentId)
                ->first(['order_id']);

            if ($locator === null) {
                throw new DomainException(
                    'Fiscal61 draft not found'
                );
            }

            DB::table('orders')
                ->where('id', $locator->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $document = $this->drafts->read(
                $documentId
            );

            $this->verifyLiveSource($document);

            $existing = DB::table(
                'checkout_fiscal_tickets62'
            )
                ->where('document_id', $documentId)
                ->first(['id']);

            if ($existing !== null) {
                return $this->audit->ticket(
                    (int) $existing->id
                );
            }

            $related = null;

            if ($document['kind'] === 'refund') {
                $original = DB::table(
                    'checkout_fiscal_tickets62'
                )
                    ->where(
                        'document_id',
                        $document['sale_document_id']
                    )
                    ->first();

                if (
                    $original === null
                    || $original->status !== 'done'
                    || $original->fiscal_code === null
                ) {
                    throw new DomainException(
                        'Original real fiscal sale must be DONE before refund'
                    );
                }

                $this->audit->ticket(
                    (int) $original->id
                );

                $related = (string)
                $original->provider_receipt_id;
            }

            $receiptId = (string) Str::uuid();

            $request = $this->envelope->build(
                $document,
                $receiptId,
                $related
            );

            $json = $this->signer->json(
                $request
            );

            $identity = [
                'document_id' => $documentId,
                'provider_receipt_id' => $receiptId,
                'related_receipt_id' => $related,
                'request_sha256' =>
                    hash('sha256', $json),
                'prepared_by' => $actor,
            ];

            $id = (int) DB::table(
                'checkout_fiscal_tickets62'
            )->insertGetId([
                ...$identity,
                'request_json' => $json,
                'identity_hmac' =>
                    $this->signer->digest($identity),
                'status' => 'prepared',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->event(
                $id,
                'prepared',
                'Prepared from signed Fiscal61 draft',
                $actor
            );

            return $this->audit->ticket($id);
        }, 3);
    }

    public function requestSend(int $ticketId): array
    {
        $actor = $this->access->actor(
            Fiscal62Access::SEND
        );

        $this->liveEnabled();

        $ticket = $this->audit->ticket($ticketId);

        if ($actor === $ticket['prepared_by']) {
            throw new DomainException(
                'Fiscal sender must differ from document preparer'
            );
        }

        if ($ticket['status'] !== 'prepared') {
            return $ticket;
        }

        Fiscal62SendJob::dispatch(
            $ticketId,
            $actor
        )
            ->onQueue('fiscal-send62')
            ->afterCommit();

        return [
            ...$ticket,
            'queue_requested' => true,
        ];
    }

    /**
     * Queue-only.
     *
     * A persisted attempt MUST happen before HTTP POST.
     */
    public function sendOnce(
        int $ticketId,
        int $actor
    ): array {
        $this->liveEnabled();

        if ($actor <= 0) {
            throw new DomainException(
                'Invalid fiscal sender'
            );
        }

        $claimed = DB::transaction(function () use (
            $ticketId,
            $actor
        ): ?array {
            $locator = DB::table(
                'checkout_fiscal_tickets62 as t'
            )
                ->join(
                    'checkout_fiscal_documents61 as d',
                    'd.id',
                    '=',
                    't.document_id'
                )
                ->where('t.id', $ticketId)
                ->first(['d.order_id']);

            if ($locator === null) {
                throw new DomainException(
                    'Fiscal ticket not found'
                );
            }

            DB::table('orders')
                ->where('id', $locator->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked = DB::table(
                'checkout_fiscal_tickets62'
            )
                ->where('id', $ticketId)
                ->lockForUpdate()
                ->first();

            $data = $this->audit->ticket(
                $ticketId
            );

            if ($actor === $data['prepared_by']) {
                throw new DomainException(
                    'Fiscal sender must be independent'
                );
            }

            if (
                $locked->attempted_at !== null
                || $locked->status !== 'prepared'
            ) {
                return null;
            }

            $draft = $this->drafts->read(
                $data['document_id']
            );

            $this->verifyLiveSource($draft);

            if ($draft['kind'] === 'refund') {
                $original = DB::table(
                    'checkout_fiscal_tickets62'
                )
                    ->where(
                        'document_id',
                        $draft['sale_document_id']
                    )
                    ->first();

                if (
                    $original === null
                    || $original->status !== 'done'
                    || (string) $original->provider_receipt_id
                    !== $data['related_receipt_id']
                ) {
                    throw new DomainException(
                        'Original fiscal receipt not confirmed'
                    );
                }
            }

            DB::table('checkout_fiscal_tickets62')
                ->where('id', $ticketId)
                ->update([
                    'status' => 'transmitting',
                    'attempted_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->audit->event(
                $ticketId,
                'post_claimed',
                'Single external POST claimed',
                $actor
            );

            return $data['request'];
        }, 3);

        if ($claimed === null) {
            return $this->audit->ticket($ticketId);
        }

        // Regardless of the HTTP outcome, no automatic second POST.
        $detail = 'Transport result unknown; use provider GET';

        try {
            $response = $this->checkbox->post(
                $claimed
            );

            $detail = 'POST HTTP '
                . (int) $response['http_status']
                . '; GET verification required';
        } catch (Throwable $exception) {
            report($exception);
        }

        DB::transaction(function () use (
            $ticketId,
            $actor,
            $detail
        ): void {
            $row = DB::table(
                'checkout_fiscal_tickets62'
            )
                ->where('id', $ticketId)
                ->lockForUpdate()
                ->first();

            if (
                $row !== null
                && $row->status === 'transmitting'
            ) {
                DB::table(
                    'checkout_fiscal_tickets62'
                )
                    ->where('id', $ticketId)
                    ->update([
                        'status' => 'uncertain',
                        'updated_at' => now(),
                    ]);

                $this->audit->event(
                    $ticketId,
                    'post_finished',
                    $detail,
                    $actor
                );
            }
        }, 3);

        return $this->audit->ticket($ticketId);
    }

    public function show(int $ticketId): array
    {
        $this->access->actor(
            Fiscal62Access::VIEW
        );

        return $this->audit->ticket($ticketId);
    }

    private function verifyLiveSource(array $document): void
    {
        $sale = $this->sources->sale(
            (int) $document['capture_receipt_id']
        );

        if ($document['kind'] === 'sale') {
            $source = $sale;
        } else {
            $parent = $this->drafts->read(
                (int) $document['sale_document_id']
            );

            $source = $this->sources->refund(
                (int) $document['source_id'],
                $sale,
                $parent['payload']
            );
        }

        if (
            !hash_equals(
                (string) $source['source_sha256'],
                (string) $document['source_sha256']
            )
            || !hash_equals(
                $this->signer->json($source['payload']),
                $this->signer->json($document['payload'])
            )
        ) {
            throw new RuntimeException(
                'Fiscal source evidence changed since preparation'
            );
        }
    }

    private function enabled(): void
    {
        if (config('fiscal62.enabled') !== true) {
            throw new DomainException(
                'Fiscal62 disabled'
            );
        }
    }

    private function liveEnabled(): void
    {
        $this->enabled();

        if (
            config('fiscal62.receipt_contract_certified') !== true
            || config('fiscal62.live_enabled') !== true
            || config('fiscal62.merchant_tax_reviewed') !== true
            || config('fiscal62.mono_auto_fiscalization_disabled') !== true
        ) {
            throw new DomainException(
                'Fiscal62 live prerequisites not approved'
            );
        }
    }
}
