<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodBankEvidenceVerifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class CheckoutCodBankEvidenceVerifierTest extends TestCase
{
    private CheckoutCodBankEvidenceVerifier $verifier;
    private string $secretKey;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_seed_keypair(str_repeat("\x07", 32));
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        $this->verifier = new CheckoutCodBankEvidenceVerifier();
        config()->set('services.cod_bank_evidence', [
            'enabled' => true,
            'providers' => [
                'provider_primary' => [
                    'enabled' => true,
                    'account_alias' => 'cod_primary_ua',
                    'public_keys' => ['active' => base64_encode($public)],
                ],
            ],
        ]);
    }

    private function raw(array $changes = []): string
    {
        return json_encode(array_merge([
            'event_key' => (string) Str::uuid(),
            'event_kind' => 'credit',
            'bank_reference' => 'BANKREF_EVIDENCE_1001',
            'bank_transaction_id' => 'BANKTX_EVIDENCE_1001',
            'reverses_transaction_id' => null,
            'amount_minor' => 680,
            'currency_code' => 'UAH',
            'account_alias' => 'cod_primary_ua',
            'occurred_at_epoch' => now()->timestamp,
        ], $changes), JSON_THROW_ON_ERROR);
    }

    private function envelope(string $body, string $timestamp, string $nonce): string
    {
        return base64_encode(sodium_crypto_sign_detached(
            CheckoutCodBankEvidenceVerifier::signedMessage(
                'provider_primary', 'active', $timestamp, $nonce, $body
            ), $this->secretKey
        ));
    }

    public function test_cod_bank_evidence29_valid_ed25519_signature(): void
    {
        $body = $this->raw();
        $time = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $this->assertSame((int) $time, $this->verifier->verify(
            $body, 'provider_primary', 'active', $time, $nonce,
            $this->envelope($body, $time, $nonce)
        ));
    }

    public function test_cod_bank_evidence29_tampering_rejected(): void
    {
        $body = $this->raw();
        $time = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $this->expectException(AuthorizationException::class);
        $this->verifier->verify(
            $body . ' ', 'provider_primary', 'active', $time, $nonce,
            $this->envelope($body, $time, $nonce)
        );
    }

    public function test_cod_bank_evidence29_unknown_key_rejected(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->verifier->verify(
            $this->raw(), 'provider_primary', 'unknown',
            (string) now()->timestamp, (string) Str::uuid(), base64_encode(str_repeat('a', 64))
        );
    }

    public function test_cod_bank_evidence29_disabled_fails_closed(): void
    {
        config()->set('services.cod_bank_evidence.enabled', false);
        $this->expectException(AuthorizationException::class);
        $this->verifier->verify(
            $this->raw(), 'provider_primary', 'active',
            (string) now()->timestamp, (string) Str::uuid(), ''
        );
    }

    public function test_cod_bank_evidence29_expired_envelope_rejected(): void
    {
        $body = $this->raw();
        $time = (string) (now()->timestamp - 301);
        $nonce = (string) Str::uuid();
        $this->expectException(AuthorizationException::class);
        $this->verifier->verify(
            $body, 'provider_primary', 'active', $time, $nonce,
            $this->envelope($body, $time, $nonce)
        );
    }

    public function test_cod_bank_evidence29_wrong_account_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->verifier->payload(
            $this->raw(['account_alias' => 'other_bank_account']),
            'cod_primary_ua', now()->timestamp
        );
    }

    public function test_cod_bank_evidence29_float_amount_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->verifier->payload(
            $this->raw(['amount_minor' => 680.5]),
            'cod_primary_ua', now()->timestamp
        );
    }

    public function test_cod_bank_evidence29_reversal_requires_original_transaction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->verifier->payload(
            $this->raw(['event_kind' => 'reversal']),
            'cod_primary_ua', now()->timestamp
        );
    }

    public function test_cod_bank_evidence29_old_bank_operation_allowed_inside_window(): void
    {
        $data = $this->verifier->payload(
            $this->raw(['occurred_at_epoch' => now()->timestamp - 30 * 86400]),
            'cod_primary_ua', now()->timestamp
        );
        $this->assertSame('UAH', $data['currency_code']);
        $this->assertSame(680, $data['amount_minor']);
    }
}
