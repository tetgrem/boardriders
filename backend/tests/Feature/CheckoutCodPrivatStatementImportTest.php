<?php

namespace Tests\Feature;

use App\Services\Pricing\CheckoutCodPrivatStatementImportService;
use App\Services\Pricing\CheckoutCodPrivatStatementStatusService;
use App\Services\Pricing\CheckoutCodTtnInvestigationStatusService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class CheckoutCodPrivatStatementImportTest extends TestCase
{
    use RefreshDatabase;

    private const IBAN = 'UA111111111111111111111111111';
    private const TOKEN = 'unit-test-privat-autoclient-token-0001';

    protected function setUp(): void
    {
        parent::setUp();

        if (
            DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new RuntimeException(
                'Requires PostgreSQL boardriders_test'
            );
        }

        $this->actor(3001);

        Gate::define(
            CheckoutCodPrivatStatementImportService::IMPORT_ABILITY,
            static fn($u): bool => (int)$u->getAuthIdentifier() === 3001
        );

        Gate::define(
            CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY,
            static fn($u): bool => (int)$u->getAuthIdentifier() === 3002
        );

        config()->set('services.privatbank_autoclient', [
            'enabled' => true,
            'token' => self::TOKEN,
            'account_iban' => self::IBAN,
            'account_alias' => 'pb_primary_ua',
        ]);

        Http::preventStrayRequests();
    }

    private function actor(int $id): void
    {
        Auth::setUser(new GenericUser(['id' => $id]));
    }

    private function date(): string
    {
        return now('Europe/Kyiv')->subDay()->toDateString();
    }

    private function posted(
        string $ref = 'BANKREF_TEST_30',
        string $refn = '1',
        string $sum = '6.80'
    ): array
    {
        return [
            'AUT_MY_ACC' => self::IBAN,
            'FL_REAL' => 'r',
            'PR_PR' => 'r',
            'CCY' => 'UAH',
            'TRANTYPE' => 'C',
            'REF' => $ref,
            'REFN' => $refn,
            'SUM' => $sum,
            'DAT_OD' => now('Europe/Kyiv')
                ->subDay()->format('d.m.Y'),
            'AUT_CNTR_NAM' => 'PRIVATE COUNTERPARTY NAME',
        ];
    }

    private function bankResponse(
        array   $rows,
        bool    $more = false,
        ?string $next = null
    ): array
    {
        return [
            'status' => 'SUCCESS',
            'type' => 'transactions',
            'exist_next_page' => $more,
            'next_page_id' => $next,
            'transactions' => $rows,
        ];
    }

    private function fake(
        array $body,
        int   $status = 200
    ): void
    {
        Http::fake([
            'https://acp.privatbank.ua/api/statements/transactions*' =>
                Http::response($body, $status),
        ]);
    }

    private function runImport(
        ?string $key = null,
        ?string $from = null,
        ?string $to = null
    ): array
    {
        return app(
            CheckoutCodPrivatStatementImportService::class
        )->import(
            $key ?? (string)Str::uuid(),
            $from ?? $this->date(),
            $to ?? $this->date()
        );
    }

    private function referenceHmac(string $ref): string
    {
        return hash_hmac(
            'sha256',
            json_encode(
                ['cod-bank-reference-v1', $ref],
                JSON_THROW_ON_ERROR
            ),
            (string)config('app.key')
        );
    }

    public function test_privat_statement30_disabled_connector_fails_closed(): void
    {
        config()->set(
            'services.privatbank_autoclient.enabled',
            false
        );

        $this->expectException(DomainException::class);
        $this->runImport();
    }

    public function test_privat_statement30_import_requires_permission(): void
    {
        $this->actor(999);

        $this->expectException(AuthorizationException::class);
        $this->runImport();
    }

    public function test_privat_statement30_rejects_invalid_date_range(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->runImport(
            from: '2026-02-31',
            to: '2026-03-01'
        );
    }

    public function test_privat_statement30_imports_only_posted_rows_without_raw_pii(): void
    {
        $this->fake($this->bankResponse([
            $this->posted(),
            array_merge(
                $this->posted(
                    'BANKREF_DEBIT_30',
                    '2',
                    '0.50'
                ),
                ['TRANTYPE' => 'D']
            ),
            array_merge(
                $this->posted(
                    'BANKREF_PENDING_30',
                    '3'
                ),
                ['PR_PR' => 'p']
            ),
        ]));

        $result = $this->runImport();

        $this->assertSame(3, $result['received_count']);
        $this->assertSame(2, $result['posted_count']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame(
            2,
            $result['first_seen_transactions_count']
        );

        $this->assertFalse($result['cod_paid']);

        $this->assertDatabaseCount(
            'checkout_cod_privat_statement_entries',
            2
        );

        $row = DB::table(
            'checkout_cod_privat_statement_entries'
        )
            ->where('direction', 'credit')
            ->first();

        $this->assertSame(
            680,
            (int)$row->amount_minor
        );

        $this->assertSame(
            $this->referenceHmac('BANKREF_TEST_30'),
            $row->reference_hmac
        );

        $this->assertStringNotContainsString(
            'PRIVATE COUNTERPARTY NAME',
            json_encode($row, JSON_THROW_ON_ERROR)
        );

        $this->assertStringNotContainsString(
            self::IBAN,
            json_encode($row, JSON_THROW_ON_ERROR)
        );

        Http::assertSent(static fn($r): bool => str_starts_with(
                $r->url(),
                'https://acp.privatbank.ua/api/statements/transactions?'
            )
            && $r->hasHeader('token', self::TOKEN)
        );
    }

    public function test_privat_statement30_replay_requires_no_extra_api_request(): void
    {
        $this->fake(
            $this->bankResponse([$this->posted()])
        );

        $key = (string)Str::uuid();
        $first = $this->runImport($key);
        $second = $this->runImport($key);

        $this->assertSame(
            $first['run_id'],
            $second['run_id']
        );

        $this->assertTrue($second['idempotent_replay']);

        Http::assertSentCount(1);
    }

    public function test_privat_statement30_repeat_period_new_key_no_duplicate_transaction(): void
    {
        $this->fake(
            $this->bankResponse([$this->posted()])
        );

        $this->runImport();
        $second = $this->runImport();

        $this->assertSame(
            0,
            $second['first_seen_transactions_count']
        );

        $this->assertDatabaseCount(
            'checkout_cod_privat_statement_entries',
            1
        );

        $this->assertDatabaseCount(
            'checkout_cod_privat_import_runs',
            2
        );
    }

    public function test_privat_statement30_changed_amount_same_bank_transaction_rejected_atomically(): void
    {
        Http::fakeSequence()
            ->push(
                $this->bankResponse([$this->posted()]),
                200
            )
            ->push(
                $this->bankResponse([
                    $this->posted(sum: '7.00'),
                ]),
                200
            );

        $this->runImport();

        $this->expectException(DomainException::class);

        try {
            $this->runImport();
        } finally {
            $this->assertDatabaseCount(
                'checkout_cod_privat_import_runs',
                1
            );

            $this->assertDatabaseCount(
                'checkout_cod_privat_statement_entries',
                1
            );
        }
    }


    public function test_privat_statement30_pagination_uses_follow_id(): void
    {
        Http::fakeSequence()
            ->push(
                $this->bankResponse(
                    [$this->posted()],
                    true,
                    'CURSOR_30'
                ),
                200
            )
            ->push(
                $this->bankResponse([
                    $this->posted(
                        'BANKREF_TEST_31',
                        '1'
                    ),
                ]),
                200
            );

        $result = $this->runImport();

        $this->assertSame(2, $result['page_count']);

        $this->assertSame(
            2,
            $result['first_seen_transactions_count']
        );

        Http::assertSentCount(2);

        Http::assertSent(static fn ($r): bool =>
        str_contains(
            $r->url(),
            'followId=CURSOR_30'
        )
        );
    }

    public function test_privat_statement30_http_error_does_not_create_import(): void
    {
        $this->fake(
            ['error' => 'temporary'],
            503
        );

        $this->expectException(DomainException::class);

        try {
            $this->runImport();
        } finally {
            $this->assertDatabaseCount(
                'checkout_cod_privat_import_runs',
                0
            );
        }
    }

    public function test_privat_statement30_bank_account_mismatch_fails_closed(): void
    {
        $this->fake($this->bankResponse([
            array_merge(
                $this->posted(),
                [
                    'AUT_MY_ACC' =>
                        'UA222222222222222222222222222',
                ]
            ),
        ]));

        $this->expectException(DomainException::class);
        $this->runImport();
    }

    public function test_privat_statement30_status_correlates_without_marking_paid(): void
    {
        $this->fake(
            $this->bankResponse([$this->posted()])
        );

        $this->runImport();
        $this->actor(3002);

        $state = app(
            CheckoutCodPrivatStatementStatusService::class
        )->snapshot(
            $this->referenceHmac('BANKREF_TEST_30'),
            680
        );

        $this->assertSame(
            'bank_statement_candidate_unverified',
            $state['status']
        );

        $this->assertFalse(
            $state['funds_received_verified']
        );

        $this->assertFalse($state['cod_paid']);
    }

    public function test_privat_statement30_mismatch_flags_investigation(): void
    {
        $this->fake($this->bankResponse([
            $this->posted(sum: '5.00'),
        ]));

        $this->runImport();
        $this->actor(3002);

        $state = app(
            CheckoutCodPrivatStatementStatusService::class
        )->snapshot(
            $this->referenceHmac('BANKREF_TEST_30'),
            680
        );

        $this->assertTrue(
            $state['requires_investigation']
        );

        $this->assertSame(
            'bank_statement_amount_discrepancy',
            $state['status']
        );
    }

    public function test_privat_statement30_debit_flags_investigation(): void
    {
        $this->fake($this->bankResponse([
            array_merge(
                $this->posted(),
                ['TRANTYPE' => 'D']
            ),
        ]));

        $this->runImport();
        $this->actor(3002);

        $state = app(
            CheckoutCodPrivatStatementStatusService::class
        )->snapshot(
            $this->referenceHmac('BANKREF_TEST_30'),
            680
        );

        $this->assertSame(
            'bank_debit_requires_investigation',
            $state['status']
        );
    }

    public function test_privat_statement30_queue_hides_reference_and_account(): void
    {
        $this->fake(
            $this->bankResponse([$this->posted()])
        );

        $this->runImport();
        $this->actor(3002);

        $queue = app(
            CheckoutCodPrivatStatementStatusService::class
        )->recent();

        $json = json_encode(
            $queue,
            JSON_THROW_ON_ERROR
        );

        $this->assertCount(
            1,
            $queue['items']
        );

        $this->assertStringNotContainsString(
            'BANKREF_TEST_30',
            $json
        );

        $this->assertStringNotContainsString(
            self::IBAN,
            $json
        );

        $this->assertStringNotContainsString(
            self::TOKEN,
            $json
        );

        $this->assertFalse(
            $queue['automatic_payment_posting_allowed']
        );
    }

    public function test_privat_statement30_reader_permission_required(): void
    {
        $this->actor(999);

        $this->expectException(
            AuthorizationException::class
        );

        app(
            CheckoutCodPrivatStatementStatusService::class
        )->recent();
    }

    public function test_privat_statement30_immutable_audit_records(): void
    {
        $this->fake(
            $this->bankResponse([$this->posted()])
        );

        $this->runImport();

        $this->expectException(
            QueryException::class
        );

        DB::table(
            'checkout_cod_privat_statement_entries'
        )->update([
            'amount_minor' => 1,
        ]);
    }


}
