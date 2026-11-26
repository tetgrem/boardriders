<?php

declare(strict_types=1);

namespace App\Services\Warehouse72;

use App\Models\User;
use App\Services\Pricing\CheckoutOrderReturnSigner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class Warehouse72Audit
{
    public function __construct(
        private readonly CheckoutOrderReturnSigner $signer
    ) {}

    public function actor(bool $write = false): int
    {
        if (config('warehouse72.enabled') !== true
            || config('crm65.enabled') !== true) {
            abort(503, 'Warehouse administration disabled');
        }

        $user = Auth::user();

        if (!$user instanceof User) {
            abort(401);
        }

        Gate::forUser($user)->authorize(
            $write ? 'warehouse72.manage' : 'warehouse72.view'
        );

        return (int) $user->id;
    }

    public function canonical(array $data): string
    {
        return $this->signer->json($data);
    }

    public function append(
        string $key,
        int $actor,
        string $kind,
        ?int $warehouseId,
        ?string $reference,
        array $request,
        array $result
    ): array {
        $event = [
            'operation_key' => strtolower($key),
            'actor_id' => $actor,
            'kind' => $kind,
            'warehouse_id' => $warehouseId,
            'reference' => $reference,
            'request_json' => $this->canonical($request),
            'result_json' => $this->canonical($result),
            'created_at' => CarbonImmutable::now('UTC')
                ->format('Y-m-d\TH:i:s.u\Z'),
        ];

        $id = (int) DB::table('checkout_warehouse_documents72')
            ->insertGetId([
                ...$event,
                'event_hmac' => $this->signature($event),
            ]);

        return [
            'document_id' => $id,
            'kind' => $kind,
            'result' => $result,
            'replayed' => false,
        ];
    }

    public function verify(object $row): array
    {
        $event = [
            'operation_key' => strtolower((string) $row->operation_key),
            'actor_id' => (int) $row->actor_id,
            'kind' => (string) $row->kind,
            'warehouse_id' => $row->warehouse_id === null
                ? null : (int) $row->warehouse_id,
            'reference' => $row->reference,
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
                'Warehouse document signature mismatch'
            );
        }

        return [
            'document_id' => (int) $row->id,
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
            'domain' => 'boardriders-warehouse72-v1',
            ...$event,
        ]);
    }
}
