<?php

declare(strict_types=1);

namespace App\Services\Pricing73;

use App\Models\User;
use App\Services\Pricing\CheckoutOrderReturnSigner;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class Pricing73Audit
{
    public function __construct(
        private readonly CheckoutOrderReturnSigner $signer
    ) {}

    public function actor(bool $write = false): int
    {
        if (config('pricing73.enabled') !== true
            || config('crm65.enabled') !== true) {
            abort(503, 'Pricing administration disabled');
        }

        $user = Auth::user();

        if (!$user instanceof User) {
            abort(401);
        }

        Gate::forUser($user)->authorize(
            $write ? 'pricing73.manage' : 'pricing73.view'
        );

        return (int) $user->id;
    }

    public function execute(
        string $key,
        string $action,
        array $request,
        Closure $callback
    ): array {
        $actor = $this->actor(true);
        $key = strtolower($key);

        return DB::transaction(function () use (
            $actor, $key, $action, $request, $callback
        ): array {
            DB::select(
                'SELECT pg_advisory_xact_lock(730073)'
            );

            $requestJson = $this->signer->json($request);

            $existing = DB::table('checkout_pricing_admin_events73')
                ->where('operation_key', $key)
                ->first();

            if ($existing !== null) {
                $event = $this->verify($existing);

                if ($event['actor_id'] !== $actor
                    || $event['action'] !== $action
                    || $event['request_json'] !== $requestJson) {
                    throw new DomainException(
                        'Ключ операции уже использован'
                    );
                }

                return [
                    'event_id' => $event['id'],
                    'result' => $event['result'],
                    'replayed' => true,
                ];
            }

            $result = $callback();

            $event = [
                'operation_key' => $key,
                'actor_id' => $actor,
                'action' => $action,
                'request_json' => $requestJson,
                'result_json' => $this->signer->json($result),
                'created_at' => CarbonImmutable::now('UTC')
                    ->format('Y-m-d\TH:i:s.u\Z'),
            ];

            $id = (int) DB::table('checkout_pricing_admin_events73')
                ->insertGetId([
                    ...$event,
                    'event_hmac' => $this->signature($event),
                ]);

            return [
                'event_id' => $id,
                'result' => $result,
                'replayed' => false,
            ];
        }, 3);
    }

    public function verify(object $row): array
    {
        $event = [
            'operation_key' => strtolower((string) $row->operation_key),
            'actor_id' => (int) $row->actor_id,
            'action' => (string) $row->action,
            'request_json' => (string) $row->request_json,
            'result_json' => (string) $row->result_json,
            'created_at' => CarbonImmutable::parse(
                (string) $row->created_at
            )->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ];

        if (!hash_equals(
            (string) $row->event_hmac,
            $this->signature($event)
        )) {
            throw new RuntimeException(
                'Pricing73 audit signature mismatch'
            );
        }

        return [
            'id' => (int) $row->id,
            ...$event,
            'request' => json_decode(
                $event['request_json'],
                true,
                512,
                JSON_THROW_ON_ERROR
            ),
            'result' => json_decode(
                $event['result_json'],
                true,
                512,
                JSON_THROW_ON_ERROR
            ),
        ];
    }

    private function signature(array $event): string
    {
        return $this->signer->digest([
            'domain' => 'boardriders-pricing73-v1',
            ...$event,
        ]);
    }
}
