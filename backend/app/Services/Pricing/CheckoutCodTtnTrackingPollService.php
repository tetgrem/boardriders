<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** Read-only polling. No response here can pay COD or ship an order. */
final class CheckoutCodTtnTrackingPollService
{
    public const POLL_ABILITY = 'nova-poshta.ttn-tracking.poll';

    public function __construct(
        private readonly NovaPoshtaTtnTrackingProbe $probe
    ) {
    }

    /** @return array<string,mixed> */
    public function poll(
        string $trackingKey,
        string $operationKey
    ): array {
        return $this->record(
            $trackingKey,
            $operationKey,
            $this->actor()
        );
    }

    /**
     * Scheduler-only read operation.
     * Audit actor 0 means a background scheduler, not a human.
     *
     * @return array<string,mixed>
     */
    public function pollScheduled(
        string $trackingKey,
        string $operationKey
    ): array {
        if (!app()->runningInConsole()) {
            throw new AuthorizationException(
                'Scheduled tracking requires console runtime'
            );
        }

        return $this->record($trackingKey, $operationKey, 0);
    }

    /** @return array<string,mixed> */
    private function record(
        string $trackingKey,
        string $operationKey,
        int $actor
    ): array {
        if (
            !Str::isUuid($trackingKey)
            || !Str::isUuid($operationKey)
        ) {
            throw new InvalidArgumentException(
                'Invalid tracking poll UUID'
            );
        }

        $trackingKey = strtolower($trackingKey);
        $operationKey = strtolower($operationKey);

        $case = DB::table('checkout_cod_ttn_tracking_cases as c')
            ->join(
                'checkout_cod_ttn_reconciliation_scans as s',
                's.id',
                '=',
                'c.scan_id'
            )
            ->where('c.tracking_key', $trackingKey)
            ->first([
                'c.id',
                'c.intent_id',
                'c.candidate_number_hmac',
                's.candidate_number_ciphertext',
                's.candidate_number_hmac as scan_hmac',
            ]);

        if (
            $case === null
            || !hash_equals(
                (string) $case->candidate_number_hmac,
                (string) $case->scan_hmac
            )
        ) {
            throw new DomainException(
                'Tracking source missing or inconsistent'
            );
        }

        $signature = $this->digest([
            'cod-ttn-tracking-poll-v1',
            $trackingKey,
            $operationKey,
            $actor,
            (int) $case->id,
            (string) $case->candidate_number_hmac,
        ]);

        $existing = DB::table('checkout_cod_ttn_tracking_observations')
            ->where('operation_key', $operationKey)
            ->first();

        if ($existing !== null) {
            return $this->replay(
                $existing,
                $case,
                $actor,
                $signature
            );
        }

        try {
            $number = Crypt::decryptString(
                (string) $case->candidate_number_ciphertext
            );
        } catch (DecryptException) {
            throw new DomainException(
                'Cannot decrypt tracking candidate'
            );
        }

        if (
            !preg_match('/^[0-9]{14}$/D', $number)
            || !hash_equals(
                (string) $case->candidate_number_hmac,
                hash_hmac('sha256', $number, $this->secret())
            )
        ) {
            throw new DomainException('TTN candidate integrity failure');
        }

        $started = now();

        try {
            $result = $this->probe->inspect($number);
        } catch (RuntimeException) {
            // Raw exceptions can contain sensitive provider data.
            $result = [
                'outcome' => 'provider_failure',
                'status_code' => null,
            ];
        }

        return DB::transaction(function () use (
            $case,
            $operationKey,
            $actor,
            $signature,
            $result,
            $started
        ): array {
            DB::table('checkout_cod_ttn_submission_intents')
                ->where('id', $case->intent_id)
                ->lockForUpdate()
                ->first(['id']);

            $old = DB::table('checkout_cod_ttn_tracking_observations')
                ->where('operation_key', $operationKey)
                ->first();

            if ($old !== null) {
                return $this->replay(
                    $old,
                    $case,
                    $actor,
                    $signature
                );
            }

            $id = DB::table('checkout_cod_ttn_tracking_observations')
                ->insertGetId([
                    'operation_key' => $operationKey,
                    'tracking_case_id' => $case->id,
                    'outcome' => $result['outcome'],
                    'status_code' => $result['status_code'],
                    'observed_by' => $actor,
                    'request_hmac' => $signature,
                    'observed_started_at' => $started,
                    'created_at' => now(),
                ]);

            return $this->present(
                DB::table('checkout_cod_ttn_tracking_observations')
                    ->where('id', $id)
                    ->first()
            );
        }, 3);
    }

    /** @return array<string,mixed> */
    private function replay(
        object $row,
        object $case,
        int $actor,
        string $signature
    ): array {
        if (
            (int) $row->tracking_case_id !== (int) $case->id
            || (int) $row->observed_by !== $actor
            || !hash_equals(
                (string) $row->request_hmac,
                $signature
            )
        ) {
            throw new DomainException(
                'Poll UUID reused for another request'
            );
        }

        return $this->present($row);
    }

    private function actor(): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            (!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(self::POLL_ABILITY)
        ) {
            throw new AuthorizationException(
                'TTN tracking poll permission denied'
            );
        }

        return (int) $id;
    }

    private function secret(): string
    {
        $secret = (string) config('app.key');

        if ($secret === '') {
            throw new DomainException('APP_KEY missing');
        }

        return $secret;
    }

    private function digest(array $data): string
    {
        return hash_hmac(
            'sha256',
            json_encode($data, JSON_THROW_ON_ERROR),
            $this->secret()
        );
    }

    /** @return array<string,mixed> */
    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'tracking_case_id' => (int) $row->tracking_case_id,
            'outcome' => (string) $row->outcome,
            'status_code' => $row->status_code,
            'observed_by' => (int) $row->observed_by,
            'carrier_status_is_observation_only' => true,
            'ttn_linked_to_order' => false,
            'delivery_confirmed_by_tracking' => false,
            'cod_paid' => false,
            'inventory_shipped' => false,
            'created_at' => (string) $row->created_at,
        ];
    }
}
