<?php

declare(strict_types=1);

namespace App\Services\Crm74;

use App\Models\User;
use App\Services\Pricing\CheckoutOrderReturnSigner;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;

final class Crm74Audit
{
    public const VIEW = 'crm74.customers.view';
    public const MANAGE = 'crm74.customers.manage';
    public const FINANCE = 'crm64.finance.view';

    public function __construct(
        private readonly CheckoutOrderReturnSigner $signer
    ) {}

    public function actor(bool $write = false): int
    {
        if (
            config('crm74.enabled') !== true
            || config('crm65.enabled') !== true
            || config('crm64.enabled') !== true
        ) {
            abort(503, 'Customer CRM disabled');
        }

        $user = Auth::user();

        if (!$user instanceof User) {
            abort(401);
        }

        Gate::forUser($user)->authorize(self::VIEW);

        if ($write) {
            Gate::forUser($user)->authorize(self::MANAGE);
        }

        return (int) $user->id;
    }

    public function has(string $ability): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && Gate::forUser($user)->allows($ability);
    }

    public function customers(): Builder
    {
        return DB::table('users as u')
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('checkout_crm_staff65 as staff')
                    ->whereColumn('staff.user_id', 'u.id');
            });
    }

    public function customer(int $id): object
    {
        $row = $this->customers()
            ->where('u.id', $id)
            ->first([
                'u.id',
                'u.name',
                'u.email',
                'u.email_verified_at',
                'u.created_at',
            ]);

        if ($row === null) {
            abort(404, 'Customer not found');
        }

        return $row;
    }

    public function viewed(
        int $customerId,
        int $actorId,
        string $section
    ): void {
        $this->append(
            $customerId,
            $actorId,
            (string) Str::uuid(),
            'view',
            ['section' => $section]
        );
    }

    public function latestContact(int $customerId): ?array
    {
        $row = DB::table('checkout_crm_customer_events74')
            ->where('customer_id', $customerId)
            ->where('kind', 'contact')
            ->orderByDesc('id')
            ->first();

        return $row === null ? null : $this->verify($row);
    }

    public function write(
        int $customerId,
        string $operationKey,
        string $kind,
        array $payload
    ): array {
        $actor = $this->actor(true);

        $operationKey = strtolower($operationKey);

        return DB::transaction(function () use (
            $customerId,
            $actor,
            $operationKey,
            $kind,
            $payload
        ): array {
            // Serialize CRM74 writes, including operation UUID checks.
            DB::select(
                'SELECT pg_advisory_xact_lock(740074)'
            );

            $this->customer($customerId);

            DB::table('users')
                ->where('id', $customerId)
                ->lockForUpdate()
                ->first(['id']);

            $existing = DB::table(
                'checkout_crm_customer_events74'
            )->where('operation_key', $operationKey)->first();

            if ($existing !== null) {
                $event = $this->verify($existing);

                if (
                    $event['customer_id'] !== $customerId
                    || $event['actor_id'] !== $actor
                    || $event['kind'] !== $kind
                    || $this->signer->json($event['payload'])
                    !== $this->signer->json($payload)
                ) {
                    throw new DomainException(
                        'Ключ операции уже использован'
                    );
                }

                return [
                    'event_id' => $event['id'],
                    'kind' => $kind,
                    'replayed' => true,
                ];
            }

            if ($kind === 'contact') {
                $previous = $this->latestContact($customerId);

                $currentId = $previous['id'] ?? null;

                if (
                    $currentId !==
                    ($payload['expected_contact_id'] ?? null)
                ) {
                    throw new DomainException(
                        'Контакты изменились. Обновите карточку'
                    );
                }
            }

            $id = $this->append(
                $customerId,
                $actor,
                $operationKey,
                $kind,
                $payload
            );

            return [
                'event_id' => $id,
                'kind' => $kind,
                'replayed' => false,
            ];
        }, 3);
    }

    private function append(
        int $customerId,
        int $actorId,
        string $key,
        string $kind,
        array $payload
    ): int {
        $event = [
            'operation_key' => strtolower($key),
            'customer_id' => $customerId,
            'actor_id' => $actorId,
            'kind' => $kind,

            'payload_ciphertext' => Crypt::encryptString(
                $this->signer->json($payload)
            ),

            'created_at' => CarbonImmutable::now('UTC')
                ->format('Y-m-d\TH:i:s.u\Z'),
        ];

        return (int) DB::table(
            'checkout_crm_customer_events74'
        )->insertGetId([
            ...$event,
            'event_hmac' => $this->signature($event),
        ]);
    }

    public function verify(object $row): array
    {
        $event = [
            'operation_key' => strtolower(
                (string) $row->operation_key
            ),

            'customer_id' => (int) $row->customer_id,
            'actor_id' => (int) $row->actor_id,
            'kind' => (string) $row->kind,

            'payload_ciphertext' =>
                (string) $row->payload_ciphertext,

            'created_at' => CarbonImmutable::parse(
                (string) $row->created_at
            )->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ];

        if (!hash_equals(
            (string) $row->event_hmac,
            $this->signature($event)
        )) {
            throw new RuntimeException(
                'CRM74 event signature mismatch'
            );
        }

        $payload = json_decode(
            Crypt::decryptString(
                $event['payload_ciphertext']
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($payload)) {
            throw new RuntimeException(
                'Invalid CRM74 event payload'
            );
        }

        return [
            'id' => (int) $row->id,

            'customer_id' => $event['customer_id'],
            'actor_id' => $event['actor_id'],
            'kind' => $event['kind'],
            'created_at' => $event['created_at'],

            'payload' => $payload,
        ];
    }

    private function signature(array $event): string
    {
        return $this->signer->digest([
            'domain' => 'boardriders-crm74-v1',
            ...$event,
        ]);
    }
}
