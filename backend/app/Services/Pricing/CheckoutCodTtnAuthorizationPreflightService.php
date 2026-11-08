<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Internal read-only gate report for a future TTN integration.
 * Never produces InternetDocument/save payload, permission or shipping authorization.
 * A trusted issuer validation path and idempotent submission workflow do not exist yet.
 */
final class CheckoutCodTtnAuthorizationPreflightService
{
    public function __construct(
        private readonly CheckoutCodReadinessReportService $readiness,
        private readonly CheckoutCodWaybillDraftService $drafts,
        private readonly CheckoutCodRecipientCarrierBindingService $bindings,
        private readonly CheckoutCodCarrierContactObservationService $contacts,
        private readonly CheckoutCodWaybillPayloadPreviewService $previews,
        private readonly NovaPoshtaCodContractStatusService $contracts,
        private readonly NovaPoshtaCodExternalAttestationStatusService $attestations,
    ) {
    }

    /** @return array<string, mixed> */
    public function inspect(string $orderKey): array
    {
        if (!Str::isUuid($orderKey)) {
            throw new InvalidArgumentException('Invalid COD order UUID');
        }
        $orderKey = strtolower($orderKey);

        return DB::transaction(function () use ($orderKey): array {
            $order = DB::table('orders')->where('order_key', $orderKey)
                ->first(['id', 'payment_status']);
            if ($order === null) {
                throw new DomainException('COD order not found');
            }
            $orderId = (int) $order->id;
            $blockers = [];
            $report = $this->readiness->inspect($orderKey);
            $local = ($report['local_checks_complete'] ?? null) === true
                && $order->payment_status === 'pending';
            if (!$local) {
                $blockers[] = 'local_cod_readiness_incomplete';
            }

            $draft = $this->drafts->findForOrder($orderId);
            $binding = $this->bindings->findForOrder($orderId);
            $observation = $this->contacts->latestForOrder($orderId);
            $sender = null;
            $profileCode = null;
            $senderCurrent = false;
            $draftPreviewValid = false;
            $declaredValueRerate = false;

            if ($draft === null) {
                $blockers[] = 'waybill_draft_missing';
            } else {
                $sender = DB::table('nova_poshta_sender_profiles')
                    ->where('id', $draft['sender_profile_id'])
                    ->first(['id', 'profile_code', 'revision']);
                if ($sender === null) {
                    $blockers[] = 'sender_profile_missing';
                } else {
                    $profileCode = (string) $sender->profile_code;
                    $latestSender = DB::table('nova_poshta_sender_profiles')
                        ->where('profile_code', $profileCode)
                        ->orderByDesc('revision')->value('id');
                    $senderCurrent = (int) $latestSender === (int) $sender->id;
                    if (!$senderCurrent) {
                        $blockers[] = 'sender_profile_superseded';
                    }
                }
                try {
                    $preview = $this->previews->preview($orderKey);
                    $draftPreviewValid = true;
                    $declaredValueRerate = in_array(
                        'declared_value_rerate_required', $preview['blockers'] ?? [], true
                    );
                } catch (DomainException|InvalidArgumentException) {
                    $blockers[] = 'waybill_preview_invalid';
                }
                if ($declaredValueRerate) {
                    $blockers[] = 'declared_value_rerate_required';
                }
            }

            $bindingCurrent = $draft !== null && $binding !== null
                && $binding['draft_id'] === $draft['id']
                && $binding['recipient_id'] === $draft['recipient_id'];
            if (!$bindingCurrent) {
                $blockers[] = 'recipient_carrier_binding_missing';
            }

            $contactCurrent = $bindingCurrent && $observation !== null
                && $observation['draft_id'] === $draft['id']
                && $observation['binding_id'] === $binding['id']
                && $observation['sender_profile_id'] === $draft['sender_profile_id']
                && $observation['sender_contact_seen'] === true
                && $observation['recipient_contact_seen'] === true;
            if (!$contactCurrent) {
                $blockers[] = 'carrier_contact_observation_missing_or_inconclusive';
            }

            $contractCurrent = false;
            $attestationCurrent = false;
            $contractStates = null;
            $attestationStates = null;
            if ($profileCode !== null) {
                $contractStates = $this->contracts->inspect($profileCode);
                $attestationStates = $this->attestations->inspect($profileCode);
                $contractCurrent = $contractStates['all_internal_reviews_current'] === true;
                $attestationCurrent =
                    $attestationStates['all_internal_attestations_current'] === true;
            }
            if (!$contractCurrent) {
                $blockers[] = 'internal_contract_reviews_incomplete';
            }
            if (!$attestationCurrent) {
                $blockers[] = 'external_source_attestations_incomplete';
            }

            $locallyPrepared = $local && $draft !== null && $senderCurrent
                && $draftPreviewValid && !$declaredValueRerate && $bindingCurrent
                && $contactCurrent && $contractCurrent && $attestationCurrent;

            // Not optional: human attestations do not establish issuer status.
            $blockers[] = 'trusted_issuer_contract_confirmation_unavailable';
            $blockers[] = 'carrier_cod_acceptance_not_verified';
            $blockers[] = 'ttn_submission_workflow_not_implemented';

            return [
                'order_id' => $orderId,
                'order_key' => $orderKey,
                'draft_id' => $draft['id'] ?? null,
                'profile_code' => $profileCode,
                'local_cod_checks_current' => $local,
                'sender_profile_current' => $senderCurrent,
                'recipient_binding_current' => $bindingCurrent,
                'carrier_contacts_observed' => $contactCurrent,
                'declared_value_rerate_required' => $declaredValueRerate,
                'internal_contract_reviews_current' => $contractCurrent,
                'external_material_reviews_current' => $attestationCurrent,
                'all_local_pre_submission_checks_current' => $locallyPrepared,
                'contract_states' => $contractStates['capabilities'] ?? null,
                'external_material_states' => $attestationStates['capabilities'] ?? null,
                'blockers' => $blockers,
                'status' => 'blocked',
                'issuer_contract_status_independently_verified' => false,
                'cod_eligibility_confirmed' => false,
                'ttn_submission_allowed' => false,
                'ttn_created' => false,
                'actual_ttn_number' => null,
                'ready_for_dispatch' => false,
            ];
        }, 3);
    }
}
