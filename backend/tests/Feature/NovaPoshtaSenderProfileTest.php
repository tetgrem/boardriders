<?php

namespace Tests\Feature;

use App\Services\Pricing\NovaPoshtaSenderProfileService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class NovaPoshtaSenderProfileTest extends TestCase
{
    use RefreshDatabase;

    private function configure(
        ?string $key = null,
        int $revision = 1,
        string $code = 'ua_primary',
        string $phone = '+380671234567',
        ?string $city = null,
    ): array {
        return app(NovaPoshtaSenderProfileService::class)->configure(
            $key ?? (string) Str::uuid(),
            $code,
            $revision,
            $city ?? '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            '33333333-3333-4333-8333-333333333333',
            '44444444-4444-4444-8444-444444444444',
            $phone,
        );
    }

    public function test_profile_is_append_only_and_never_claims_carrier_verification(): void
    {
        $saved = $this->configure();
        $this->assertSame(1, $saved['revision']);
        $this->assertFalse($saved['carrier_sender_verified']);
        $this->assertFalse($saved['merchant_cod_agreement_verified']);
        $this->assertFalse($saved['ready_for_dispatch']);
        $this->assertSame('********4567', $saved['phone_masked']);
        $this->assertDatabaseCount('nova_poshta_sender_profiles', 1);
    }

    public function test_phone_is_encrypted_at_rest(): void
    {
        $this->configure();
        $raw = DB::table('nova_poshta_sender_profiles')->value('phone_ciphertext');
        $this->assertStringNotContainsString('380671234567', $raw);
        $this->assertSame('380671234567', Crypt::decryptString($raw));
    }

    public function test_retry_with_same_key_and_data_is_idempotent(): void
    {
        $key = (string) Str::uuid();
        $this->assertSame($this->configure($key), $this->configure($key));
        $this->assertDatabaseCount('nova_poshta_sender_profiles', 1);
    }

    public function test_same_key_cannot_change_phone(): void
    {
        $key = (string) Str::uuid();
        $this->configure($key);
        $this->expectException(DomainException::class);
        $this->configure($key, phone: '+380681234567');
    }

    public function test_version_two_preserves_history_and_becomes_latest(): void
    {
        $this->configure();
        $second = $this->configure(revision: 2, phone: '+380681234567');
        $this->assertSame(2, $second['revision']);
        $this->assertSame($second, app(NovaPoshtaSenderProfileService::class)->latest('ua_primary'));
        $this->assertDatabaseCount('nova_poshta_sender_profiles', 2);
    }

    public function test_stale_or_skipped_revision_is_rejected(): void
    {
        $this->configure();
        $this->expectException(DomainException::class);
        $this->configure(revision: 3);
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->configure(phone: '+123456789');
    }

    public function test_invalid_city_reference_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->configure(city: 'not-a-carrier-uuid');
    }

    public function test_database_blocks_modification_of_profile_history(): void
    {
        $saved = $this->configure();
        $this->expectException(QueryException::class);
        DB::table('nova_poshta_sender_profiles')->where('id', $saved['id'])
            ->update(['revision' => 100]);
    }

    public function test_missing_profile_returns_null(): void
    {
        $this->assertNull(app(NovaPoshtaSenderProfileService::class)->latest('ua_primary'));
    }
}
