<?php

namespace App\Services\Pricing;

/** Conservative interpretation of historical carrier observations. */
final class CheckoutCodTtnEvidenceMatrixPolicy
{
    /**
     * @param list<array{
     *     provider_result:string,
     *     number_hmac:string,
     *     context_matches:bool,
     *     review_decision:?string,
     *     comparison_summary:?string,
     *     document_ref_seen:bool
     * }> $scans
     *
     * @return array{
     *     outcome:string,
     *     scans_count:int,
     *     carrier_ownership_verified:bool,
     *     absence_proven:bool,
     *     automatic_retry_allowed:bool,
     *     ttn_submission_allowed:bool
     * }
     */
    public function classify(array $scans): array
    {
        $conflict = false;
        $numbers = [];

        foreach ($scans as $scan) {
            $numbers[] = $scan['number_hmac'];

            if (
                !$scan['context_matches']
                || $scan['provider_result'] === 'duplicate_number'
                || $scan['comparison_summary'] === 'snapshot_mismatch'
            ) {
                $conflict = true;
            }
        }

        if (count(array_unique($numbers)) > 1) {
            $conflict = true;
        }

        $latest = $scans === []
            ? null
            : $scans[array_key_last($scans)];

        $correlated = $latest !== null
            && $latest['provider_result'] === 'number_observed_once'
            && $latest['review_decision'] === 'escalate_to_carrier'
            && $latest['comparison_summary'] === 'snapshot_consistent'
            && $latest['document_ref_seen'] === true;

        $outcome = $conflict
            ? 'conflicting_evidence'
            : (
            $correlated
                ? 'candidate_snapshot_consistent'
                : 'insufficient_evidence'
            );

        return [
            'outcome' => $outcome,
            'scans_count' => count($scans),
            'carrier_ownership_verified' => false,
            'absence_proven' => false,
            'automatic_retry_allowed' => false,
            'ttn_submission_allowed' => false,
        ];
    }
}
