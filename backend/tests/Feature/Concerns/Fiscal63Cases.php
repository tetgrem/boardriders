<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Jobs\Fiscal62PollJob;
use App\Services\Fiscal62\Fiscal62StatusService;
use App\Services\Fiscal62\Fiscal62Integrity;
use App\Services\Fiscal63\Fiscal63Access;
use App\Services\Fiscal63\Fiscal63CaseService;
use App\Services\Fiscal63\Fiscal63MonitorService;
use App\Services\Fiscal63\Fiscal63ReportService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

trait Fiscal63Cases
{
    private function uncertain63(
        int $minutes = 16
    ): array {
        $fixture = $this->fiscal62UncertainFixture();

        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')
                ->addMinutes($minutes)
        );

        return $fixture;
    }

    private function transmitting63(
        int $ageMinutes = 11
    ): array {
        $fixture = $this->fiscal62SaleFixture();

        // Existing legal database transition:
        // prepared -> transmitting.
        DB::table('checkout_fiscal_tickets62')
            ->where('id', $fixture['ticket_id'])
            ->update([
                'status' => 'transmitting',
                'attempted_at' => now(),
                'updated_at' => now(),
            ]);

        app(Fiscal62Integrity::class)->event(
            $fixture['ticket_id'],
            'post_claimed',
            'Single external POST claimed',
            6203
        );

        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')
                ->addMinutes($ageMinutes)
        );

        return $fixture;
    }

    private function openUncertain63(): array
    {
        $fixture = $this->uncertain63();

        $this->actor(6301);

        $scan = app(
            Fiscal63MonitorService::class
        )->scan();

        $this->assertSame(
            1,
            $scan['cases_opened_or_confirmed']
        );

        return [
            ...$fixture,
            'case_id' =>
                $scan['cases'][0]['case_id'],
        ];
    }

    // --------------------------------------------------------
    // 01 — RECENT UNCERTAIN: NO PREMATURE INCIDENT
    // --------------------------------------------------------

    public function test_cod_fiscal63_recent_uncertain_has_no_incident(): void
    {
        $this->fiscal62UncertainFixture();

        $this->actor(6301);

        $scan = app(
            Fiscal63MonitorService::class
        )->scan();

        $this->assertSame(
            0,
            $scan['cases_opened_or_confirmed']
        );

        $this->assertDatabaseCount(
            'checkout_fiscal_cases63',
            0
        );
    }

    // --------------------------------------------------------
    // 02 — SIGNED INCIDENT
    // --------------------------------------------------------

    public function test_cod_fiscal63_aged_uncertain_creates_signed_case(): void
    {
        $fixture = $this->openUncertain63();

        $this->actor(6307);

        $case = app(
            Fiscal63CaseService::class
        )->show(
            $fixture['case_id']
        );

        $this->assertSame(
            'uncertain_submission',
            $case['reason_code']
        );

        $this->assertSame(
            'open',
            $case['status']
        );

        $this->assertSame(
            'uncertain',
            $case['ticket_status']
        );

        $this->assertDatabaseCount(
            'checkout_fiscal_cases63',
            1
        );
    }

    // --------------------------------------------------------
    // 03 — IDEMPOTENT MONITORING
    // --------------------------------------------------------

    public function test_cod_fiscal63_second_scan_does_not_duplicate(): void
    {
        $this->openUncertain63();

        $this->actor(6301);

        $scan = app(
            Fiscal63MonitorService::class
        )->scan();

        $this->assertSame(
            0,
            $scan['cases_opened_or_confirmed']
        );

        $this->assertDatabaseCount(
            'checkout_fiscal_cases63',
            1
        );
    }

    // --------------------------------------------------------
    // 04 — NO PROVIDER REQUESTS
    // --------------------------------------------------------

    public function test_cod_fiscal63_monitor_never_sends_http_or_refunds(): void
    {
        $this->uncertain63();

        $this->replaceMonoHttpFake([]);

        $this->actor(6301);

        $result = app(
            Fiscal63MonitorService::class
        )->scan();

        $this->assertSame(
            0,
            $result['external_requests_sent']
        );

        Http::assertNothingSent();
    }

    // --------------------------------------------------------
    // 05 — STALE TRANSMISSION
    // --------------------------------------------------------

    public function test_cod_fiscal63_detects_stale_transmission(): void
    {
        $fixture = $this->transmitting63();

        $this->actor(6301);

        $scan = app(
            Fiscal63MonitorService::class
        )->scan();

        $this->assertSame(
            1,
            $scan['cases_opened_or_confirmed']
        );

        $this->assertSame(
            'stale_transmission',
            $scan['cases'][0]['reason_code']
        );

        $this->assertDatabaseHas(
            'checkout_fiscal_tickets62',
            [
                'id' => $fixture['ticket_id'],
                'status' => 'transmitting',
            ]
        );
    }

    // --------------------------------------------------------
    // 06 — FRESH TRANSMISSION IS NOT STALE
    // --------------------------------------------------------

    public function test_cod_fiscal63_fresh_transmission_not_recovered(): void
    {
        $this->transmitting63(1);

        $this->actor(6301);

        $scan = app(
            Fiscal63MonitorService::class
        )->scan();

        $this->assertSame(
            0,
            $scan['cases_opened_or_confirmed']
        );
    }

    // --------------------------------------------------------
    // 07 — MANUAL RECOVERY DOES NOT RESEND POST
    // --------------------------------------------------------

    public function test_cod_fiscal63_recovery_marks_uncertain_without_second_post(): void
    {
        $fixture = $this->transmitting63();

        $this->actor(6301);

        $scan = app(
            Fiscal63MonitorService::class
        )->scan();

        $id = $scan['cases'][0]['case_id'];

        $this->actor(6302);

        app(Fiscal63CaseService::class)
            ->acknowledge($id);

        $this->replaceMonoHttpFake([]);

        $this->actor(6304);

        $case = app(
            Fiscal63CaseService::class
        )->recoverTransmission($id);

        $this->assertSame(
            'uncertain',
            $case['ticket_status']
        );

        $this->assertSame(
            'acknowledged',
            $case['status']
        );

        $this->assertDatabaseHas(
            'checkout_fiscal_tickets62',
            [
                'id' => $fixture['ticket_id'],
                'status' => 'uncertain',
            ]
        );

        Http::assertNothingSent();

        $this->assertCount(
            2,
            $case['actions']
        );
    }

    // --------------------------------------------------------
    // 08 — RECOVERY REPLAY
    // --------------------------------------------------------

    public function test_cod_fiscal63_recovery_replay_is_idempotent(): void
    {
        $this->transmitting63();

        $this->actor(6301);

        $id = app(
            Fiscal63MonitorService::class
        )->scan()['cases'][0]['case_id'];

        $this->actor(6302);

        app(Fiscal63CaseService::class)
            ->acknowledge($id);

        $this->actor(6304);

        $service = app(
            Fiscal63CaseService::class
        );

        $first = $service->recoverTransmission($id);
        $second = $service->recoverTransmission($id);

        $this->assertSame(
            $first,
            $second
        );

        $this->assertDatabaseCount(
            'checkout_fiscal_case_actions63',
            2
        );
    }

    // --------------------------------------------------------
    // 09 — PROVIDER REVIEW FLAG
    // --------------------------------------------------------

    public function test_cod_fiscal63_monitor_tracks_needs_review_immediately(): void
    {
        $fixture = $this->fiscal62UncertainFixture();

        DB::table('checkout_fiscal_tickets62')
            ->where('id', $fixture['ticket_id'])
            ->update([
                'status' => 'needs_review',
                'updated_at' => now(),
            ]);

        $this->actor(6301);

        $scan = app(
            Fiscal63MonitorService::class
        )->scan();

        $this->assertSame(
            'provider_needs_review',
            $scan['cases'][0]['reason_code']
        );
    }

    // --------------------------------------------------------
    // 10 — PROVIDER PENDING SLA
    // --------------------------------------------------------

    public function test_cod_fiscal63_monitor_tracks_provider_pending_sla(): void
    {
        $fixture = $this->fiscal62UncertainFixture();

        DB::table('checkout_fiscal_tickets62')
            ->where('id', $fixture['ticket_id'])
            ->update([
                'status' => 'provider_pending',
                'updated_at' => now(),
            ]);

        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')
                ->addMinutes(61)
        );

        $this->actor(6301);

        $scan = app(
            Fiscal63MonitorService::class
        )->scan();

        $this->assertSame(
            'pending_too_long',
            $scan['cases'][0]['reason_code']
        );
    }

    // --------------------------------------------------------
    // 11 — BACKLOG OF UNPROCESSED DRAFTS
    // --------------------------------------------------------

    public function test_cod_fiscal63_prepared_backlog_detected_after_one_day(): void
    {
        $this->fiscal62SaleFixture();

        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')
                ->addMinutes(1441)
        );

        $this->actor(6301);

        $scan = app(
            Fiscal63MonitorService::class
        )->scan();

        $this->assertSame(
            'prepared_backlog',
            $scan['cases'][0]['reason_code']
        );
    }

    // --------------------------------------------------------
    // 12 — SEPARATION OF DUTIES
    // --------------------------------------------------------

    public function test_cod_fiscal63_opener_cannot_acknowledge_own_case(): void
    {
        $fixture = $this->openUncertain63();

        Gate::define(
            Fiscal63Access::ACK,
            static fn ($user): bool =>
            in_array(
                (int) $user->getAuthIdentifier(),
                [6301, 6302],
                true
            )
        );

        $this->actor(6301);

        $this->expectException(
            DomainException::class
        );

        app(Fiscal63CaseService::class)
            ->acknowledge(
                $fixture['case_id']
            );
    }

    // --------------------------------------------------------
    // 13 — ACKNOWLEDGEMENT AND NOTES
    // --------------------------------------------------------

    public function test_cod_fiscal63_acknowledgement_and_note_are_immutable(): void
    {
        $fixture = $this->openUncertain63();

        $this->actor(6302);

        $service = app(
            Fiscal63CaseService::class
        );

        $ack = $service->acknowledge(
            $fixture['case_id']
        );

        $again = $service->acknowledge(
            $fixture['case_id']
        );

        $this->assertSame(
            $ack,
            $again
        );

        $this->actor(6303);

        $noted = $service->addNote(
            $fixture['case_id'],
            'Provider ticket remains unresolved'
        );

        $this->assertSame(
            'acknowledged',
            $noted['status']
        );

        $this->assertSame(
            'note',
            $noted['actions'][1]['action']
        );

        $this->assertDatabaseCount(
            'checkout_fiscal_case_actions63',
            2
        );
    }

    // --------------------------------------------------------
    // 14 — NO CLOSE BEFORE DONE
    // --------------------------------------------------------

    public function test_cod_fiscal63_cannot_close_when_checkbox_unconfirmed(): void
    {
        $fixture = $this->openUncertain63();

        $this->actor(6302);

        app(Fiscal63CaseService::class)
            ->acknowledge(
                $fixture['case_id']
            );

        $this->actor(6306);

        $this->expectException(
            DomainException::class
        );

        app(Fiscal63CaseService::class)
            ->close(
                $fixture['case_id']
            );
    }

    // --------------------------------------------------------
    // 15 — VERIFIED DONE AND INDEPENDENT CLOSURE
    // --------------------------------------------------------

    public function test_cod_fiscal63_independent_close_only_after_provider_done(): void
    {
        $fixture = $this->openUncertain63();

        $this->actor(6302);

        app(Fiscal63CaseService::class)
            ->acknowledge(
                $fixture['case_id']
            );

        $this->replaceMonoHttpFake([
            'https://api.checkbox.ua/api/v1/receipts/*' =>
                Http::response([
                    'id' => $fixture['provider_id'],
                    'status' => 'DONE',
                    'fiscal_code' => 'FISCAL-63-001',
                    'is_sent_dps' => true,
                    'total_payment' => 2000,
                    'transaction' => [
                        'response_status' => 'OK',
                    ],
                ], 200),
        ]);

        $result = app(
            Fiscal62StatusService::class
        )->poll(
            $fixture['ticket_id'],
            6204
        );

        $this->assertSame(
            'done',
            $result['status']
        );

        $this->actor(6306);

        $service = app(
            Fiscal63CaseService::class
        );

        $closed = $service->close(
            $fixture['case_id']
        );

        $this->assertSame(
            'closed',
            $closed['status']
        );

        $this->assertSame(
            'FISCAL-63-001',
            $closed['fiscal_code']
        );

        $this->assertTrue(
            $closed['provider_done_evidence']
        );

        $this->assertSame(
            $closed,
            $service->close(
                $fixture['case_id']
            )
        );
    }

    // --------------------------------------------------------
    // 16 — GET QUEUE ONLY, WITH COOLDOWN
    // --------------------------------------------------------

    public function test_cod_fiscal63_manual_get_is_queued_only_with_cooldown(): void
    {
        $fixture = $this->openUncertain63();

        $this->actor(6302);

        app(Fiscal63CaseService::class)
            ->acknowledge(
                $fixture['case_id']
            );

        config()->set(
            'fiscal63.provider_poll_enabled',
            true
        );

        Queue::fake();

        $this->replaceMonoHttpFake([]);

        $this->actor(6305);

        $service = app(
            Fiscal63CaseService::class
        );

        $queued = $service->requestPoll(
            $fixture['case_id']
        );

        $this->assertTrue(
            $queued['poll_queued']
        );

        $this->assertFalse(
            $queued['http_post_performed']
        );

        Queue::assertPushed(
            Fiscal62PollJob::class,
            1
        );

        Http::assertNothingSent();

        try {
            $service->requestPoll(
                $fixture['case_id']
            );

            $this->fail(
                'Provider GET cooldown was bypassed'
            );
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'cooldown',
                $exception->getMessage()
            );
        }
    }

    // --------------------------------------------------------
    // 17 — AUTHORIZATION
    // --------------------------------------------------------

    public function test_cod_fiscal63_monitor_and_case_actions_require_authorization(): void
    {
        $fixture = $this->openUncertain63();

        $this->actor(6203);

        $this->expectException(
            AuthorizationException::class
        );

        app(Fiscal63CaseService::class)
            ->acknowledge(
                $fixture['case_id']
            );
    }

    // --------------------------------------------------------
    // 18 — POSTGRESQL IMMUTABILITY
    // --------------------------------------------------------

    public function test_cod_fiscal63_case_record_cannot_be_modified_in_postgres(): void
    {
        $fixture = $this->openUncertain63();

        $this->expectException(
            QueryException::class
        );

        DB::table('checkout_fiscal_cases63')
            ->where(
                'id',
                $fixture['case_id']
            )
            ->update([
                'reason_code' => 'prepared_backlog',
            ]);
    }

    // --------------------------------------------------------
    // 19 — CRM AND DASHBOARD
    // --------------------------------------------------------

    public function test_cod_fiscal63_crm_queue_paginates_and_counts_statuses(): void
    {
        $fixture = $this->openUncertain63();

        DB::table('checkout_fiscal_tickets62')
            ->where(
                'id',
                $fixture['ticket_id']
            )
            ->update([
                'status' => 'provider_pending',
                'updated_at' => now(),
            ]);

        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')
                ->addMinutes(61)
        );

        $this->actor(6301);

        app(Fiscal63MonitorService::class)
            ->scan();

        $this->actor(6307);

        $report = app(
            Fiscal63ReportService::class
        );

        $first = $report->list(1);

        $second = $report->list(
            1,
            $first['next_before_id']
        );

        $this->assertCount(
            1,
            $first['items']
        );

        $this->assertCount(
            1,
            $second['items']
        );

        $this->assertNotSame(
            $first['items'][0]['case_id'],
            $second['items'][0]['case_id']
        );

        $this->assertSame(
            2,
            $report->dashboard()['open_cases']
        );

        $this->assertSame(
            0,
            $report->dashboard()[
            'provider_post_requests_sent'
            ]
        );
    }

    // --------------------------------------------------------
    // 20 — ROUTES REQUIRE SANCTUM
    // --------------------------------------------------------

    public function test_cod_fiscal63_api_routes_are_sanctum_protected(): void
    {
        foreach ([
                     'fiscal63.scan',
                     'fiscal63.queue',
                     'fiscal63.dashboard',
                     'fiscal63.show',
                     'fiscal63.ack',
                     'fiscal63.note',
                     'fiscal63.recover',
                     'fiscal63.poll',
                     'fiscal63.close',
                 ] as $name) {
            $route = Route::getRoutes()
                ->getByName($name);

            $this->assertNotNull(
                $route,
                'Missing route: ' . $name
            );

            $this->assertContains(
                'auth:sanctum',
                $route->gatherMiddleware()
            );
        }
    }
}
