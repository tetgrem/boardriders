<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Append-only internal merchant sender settings. New configuration = new revision.
 * Stored references are unverified; never treat configuration as a contract.
 */
final class NovaPoshtaSenderProfileService
{
    public function __construct(private readonly NovaPoshtaSenderProfilePolicy $policy)
    {
    }

    /** @return array<string, mixed> */
    public function configure(
        string $profileKey,
        string $profileCode,
        int $revision,
        string $cityRef,
        string $counterpartyRef,
        string $warehouseRef,
        string $contactRef,
        string $phone,
    ): array {
        if (!Str::isUuid($profileKey) || $revision < 1) {
            throw new InvalidArgumentException('Invalid sender profile operation');
        }

        $key = strtolower($profileKey);
        $code = $this->policy->normalizeCode($profileCode);
        $city = $this->policy->uuid($cityRef);
        $counterparty = $this->policy->uuid($counterpartyRef);
        $warehouse = $this->policy->uuid($warehouseRef);
        $contact = $this->policy->uuid($contactRef);
        $normalizedPhone = $this->policy->phone($phone);

        $request = [$code, $revision, $city, $counterparty, $warehouse, $contact, $normalizedPhone];
        $hmac = hash_hmac(
            'sha256',
            json_encode($request, JSON_THROW_ON_ERROR),
            (string) config('app.key')
        );

        return DB::transaction(function () use (
            $key, $code, $revision, $city, $counterparty,
            $warehouse, $contact, $normalizedPhone, $hmac
        ): array {
            // Serializes even the first revision of the same profile code.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['boardriders:np-sender:' . $code]);

            $existing = DB::table('nova_poshta_sender_profiles')
                ->where('profile_key', $key)->first();
            if ($existing !== null) {
                if ((string) $existing->profile_code !== $code
                    || (int) $existing->revision !== $revision
                    || !hash_equals((string) $existing->request_hmac, $hmac)
                ) {
                    throw new DomainException('Sender profile key reused with changed data');
                }
                return $this->present($existing);
            }

            $latest = DB::table('nova_poshta_sender_profiles')
                ->where('profile_code', $code)
                ->orderByDesc('revision')->first(['revision']);
            $expected = $latest === null ? 1 : (int) $latest->revision + 1;
            if ($revision !== $expected) {
                throw new DomainException('Sender profile revision is stale or skipped');
            }

            $id = DB::table('nova_poshta_sender_profiles')->insertGetId([
                'profile_key' => $key,
                'profile_code' => $code,
                'revision' => $revision,
                'city_ref' => $city,
                'counterparty_ref' => $counterparty,
                'warehouse_ref' => $warehouse,
                'contact_ref' => $contact,
                'phone_ciphertext' => Crypt::encryptString($normalizedPhone),
                'request_hmac' => $hmac,
                'created_at' => now(),
            ]);

            return $this->present(
                DB::table('nova_poshta_sender_profiles')->where('id', $id)->first()
            );
        }, 3);
    }

    /** @return array<string, mixed>|null */
    public function latest(string $profileCode): ?array
    {
        $code = $this->policy->normalizeCode($profileCode);
        $row = DB::table('nova_poshta_sender_profiles')
            ->where('profile_code', $code)
            ->orderByDesc('revision')->first();
        return $row === null ? null : $this->present($row);
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        $phone = Crypt::decryptString((string) $row->phone_ciphertext);
        return [
            'id' => (int) $row->id,
            'profile_key' => (string) $row->profile_key,
            'profile_code' => (string) $row->profile_code,
            'revision' => (int) $row->revision,
            'city_ref' => (string) $row->city_ref,
            'counterparty_ref' => (string) $row->counterparty_ref,
            'warehouse_ref' => (string) $row->warehouse_ref,
            'contact_ref' => (string) $row->contact_ref,
            'phone_masked' => '********' . substr($phone, -4),
            'carrier_sender_verified' => false,
            'merchant_cod_agreement_verified' => false,
            'payout_contract_verified' => false,
            'ready_for_dispatch' => false,
            'created_at' => (string) $row->created_at,
        ];
    }
}
