<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * ТІЛЬКИ внутрішня ручна фіксація рішення по review-case.
 * reviewerUserId має надходити від уже авторизованого співробітника OMS.
 * Перевірка ролей/прав тут НЕ реалізована: не відкривати HTTP endpoint.
 * refund_proposed створює лише заявку, НЕ повертає гроші.
 */
final class CheckoutPaymentReviewResolutionService
{
    public function __construct(
        private readonly CheckoutPaymentCaptureReceiptService $receipts,
    ) {
    }

    /** @return array<string,mixed> */
    public function resolve(
        int $reviewCaseId,
        string $operationKey,
        int $reviewerUserId,
        string $resolution,
        string $reasonNote,
        ?int $refundAmountMinor = null,
    ): array {
        $operationKey = strtolower(trim($operationKey));
        $reasonNote = trim($reasonNote);

        if (
            $reviewCaseId <= 0 || $reviewerUserId <= 0
            || !Str::isUuid($operationKey)
            || !in_array($resolution, ['refund_proposed', 'no_refund'], true)
            || mb_strlen($reasonNote) < 10 || mb_strlen($reasonNote) > 1000
            || (
                $resolution === 'refund_proposed'
                && ($refundAmountMinor === null || $refundAmountMinor <= 0)
            )
            || ($resolution === 'no_refund' && $refundAmountMinor !== null)
        ) {
            throw new InvalidArgumentException('Invalid review resolution parameters');
        }

        return DB::transaction(function () use (
            $reviewCaseId, $operationKey, $reviewerUserId,
            $resolution, $reasonNote, $refundAmountMinor
        ): array {
            // Однаковий ключ операції у різних кейсах теж серіалізуємо.
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ['checkout-review-resolution:' . $operationKey]
            );

            $case = DB::table('checkout_payment_review_cases')
                ->where('id', $reviewCaseId)
                ->lockForUpdate()
                ->first();

            if ($case === null) {
                throw new DomainException('Payment review case not found');
            }

            $usedKey = DB::table('checkout_payment_review_resolutions')
                ->where('operation_key', $operationKey)->first();

            if ($usedKey !== null && (int) $usedKey->review_case_id !== $reviewCaseId) {
                throw new DomainException('Resolution operation key belongs to another case');
            }

            $existing = DB::table('checkout_payment_review_resolutions')
                ->where('review_case_id', $reviewCaseId)->first();

            if ($existing !== null) {
                if (
                    $existing->operation_key !== $operationKey
                    || (int) $existing->reviewer_user_id !== $reviewerUserId
                    || $existing->resolution !== $resolution
                    || $existing->reason_note !== $reasonNote
                    || ($existing->refund_amount_minor === null
                        ? null : (int) $existing->refund_amount_minor) !== $refundAmountMinor
                ) {
                    throw new DomainException('Review case already has a different resolution');
                }

                return $this->present($existing);
            }

            if (!DB::table('users')->where('id', $reviewerUserId)->exists()) {
                throw new DomainException('Review operator not found');
            }

            $decision = DB::table('checkout_payment_capture_decisions')
                ->where('id', $case->decision_id)->first();

            if ($decision === null || $decision->decision !== 'review') {
                throw new DomainException('Case does not belong to a review decision');
            }

            $receiptRow = DB::table('checkout_payment_capture_receipts')
                ->where('id', $decision->receipt_id)
                ->first(['id', 'order_id', 'provider_code', 'provider_capture_id']);

            if ($receiptRow === null || (int) $receiptRow->order_id !== (int) $decision->order_id) {
                throw new DomainException('Review receipt and order mismatch');
            }

            // Перевіряємо SHA-256 / JSON через існуючий trusted receipt reader.
            $receipt = $this->receipts->findByProviderCapture(
                (string) $receiptRow->provider_code,
                (string) $receiptRow->provider_capture_id
            );

            if (
                $receipt === null
                || $receipt['id'] !== (int) $receiptRow->id
                || $receipt['amount_minor'] !== (int) $case->captured_amount_minor
                || $receipt['currency_code'] !== $case->captured_currency_code
            ) {
                throw new DomainException('Review case and verified capture mismatch');
            }

            if (
                $resolution === 'refund_proposed'
                && $refundAmountMinor > $receipt['amount_minor']
            ) {
                throw new DomainException('Proposed refund exceeds captured amount');
            }

            // Один кейс — одне остаточне ручне рішення.
            $resolutionId = (int) DB::table('checkout_payment_review_resolutions')
                ->insertGetId([
                    'review_case_id' => $reviewCaseId,
                    'operation_key' => $operationKey,
                    'reviewer_user_id' => $reviewerUserId,
                    'resolution' => $resolution,
                    'reason_note' => $reasonNote,
                    'refund_amount_minor' => $refundAmountMinor,
                ]);

            if ($resolution === 'refund_proposed') {
                DB::table('checkout_payment_refund_intents')->insert([
                    'resolution_id' => $resolutionId,
                    'receipt_id' => $receipt['id'],
                    'refund_key' => (string) Str::uuid(),
                    'provider_code' => $receipt['provider_code'],
                    'provider_capture_id' => $receipt['provider_capture_id'],
                    'amount_minor' => $refundAmountMinor,
                    'currency_code' => $receipt['currency_code'],
                ]);
            }

            $row = DB::table('checkout_payment_review_resolutions')
                ->where('id', $resolutionId)->first();

            return $this->present($row);
        }, 3);
    }

    /** @return array<string,mixed>|null */
    public function findForCase(int $reviewCaseId): ?array
    {
        if ($reviewCaseId <= 0) {
            throw new InvalidArgumentException('Invalid review case ID');
        }

        $row = DB::table('checkout_payment_review_resolutions')
            ->where('review_case_id', $reviewCaseId)->first();

        return $row === null ? null : $this->present($row);
    }

    /** @return array<string,mixed> */
    private function present(object $row): array
    {
        $case = DB::table('checkout_payment_review_cases')
            ->where('id', $row->review_case_id)->first(['decision_id']);

        if ($case === null) {
            throw new RuntimeException('Review case missing for resolution');
        }

        $decision = DB::table('checkout_payment_capture_decisions')
            ->where('id', $case->decision_id)->first(['order_id', 'receipt_id']);

        if ($decision === null) {
            throw new RuntimeException('Payment decision missing for resolution');
        }

        $intent = DB::table('checkout_payment_refund_intents')
            ->where('resolution_id', $row->id)->first();

        if (
            ($row->resolution === 'refund_proposed' && $intent === null)
            || ($row->resolution === 'no_refund' && $intent !== null)
            || ($intent !== null && (
                    (int) $intent->receipt_id !== (int) $decision->receipt_id
                    || (int) $intent->amount_minor !== (int) $row->refund_amount_minor
                ))
        ) {
            throw new RuntimeException('Review resolution / refund intent mismatch');
        }

        return [
            'resolution_id' => (int) $row->id,
            'review_case_id' => (int) $row->review_case_id,
            'decision_id' => (int) $case->decision_id,
            'order_id' => (int) $decision->order_id,
            'receipt_id' => (int) $decision->receipt_id,
            'operation_key' => (string) $row->operation_key,
            'reviewer_user_id' => (int) $row->reviewer_user_id,
            'resolution' => (string) $row->resolution,
            'reason_note' => (string) $row->reason_note,
            'refund_amount_minor' => $row->refund_amount_minor === null
                ? null : (int) $row->refund_amount_minor,
            'refund_intent' => $intent === null ? null : [
                'id' => (int) $intent->id,
                'refund_key' => (string) $intent->refund_key,
                'provider_code' => (string) $intent->provider_code,
                'provider_capture_id' => (string) $intent->provider_capture_id,
                'amount_minor' => (int) $intent->amount_minor,
                'currency_code' => (string) $intent->currency_code,
            ],
            'created_at' => (string) $row->created_at,
        ];
    }
}
