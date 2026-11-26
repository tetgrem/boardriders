<?php

declare(strict_types=1);

namespace App\Services\Catalog71;

use App\Services\Pricing\CheckoutOrderReturnSigner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Catalog71Audit
{
    public function __construct(
        private readonly CheckoutOrderReturnSigner $signer
    ) {}

    public function append(
        string $entity,
        int $id,
        int $revision,
        int $actor,
        string $action,
        array $payload
    ): void {
        $event = [
            'entity_type' => $entity,
            'entity_id' => $id,
            'revision' => $revision,
            'actor_id' => $actor,
            'action' => $action,
            'payload_json' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            ),
            'created_at' => CarbonImmutable::now('UTC')
                ->format('Y-m-d\TH:i:s.u\Z'),
        ];

        DB::table('checkout_catalog_events71')->insert([
            ...$event,
            'event_hmac' => $this->signature($event),
        ]);
    }

    public function assertRevision(
        string $entity,
        int $id,
        int $revision
    ): void {
        $row = DB::table('checkout_catalog_events71')
            ->where('entity_type', $entity)
            ->where('entity_id', $id)
            ->orderByDesc('revision')
            ->first();

        if ($row === null) {
            if ($revision !== 0) {
                throw new RuntimeException(
                    'Catalog audit history is missing'
                );
            }

            return;
        }

        $event = $this->verify($row);

        if ($event['revision'] !== $revision) {
            throw new RuntimeException(
                'Catalog revision differs from signed history'
            );
        }
    }

    public function history(string $entity, int $id): array
    {
        return DB::table('checkout_catalog_events71')
            ->where('entity_type', $entity)
            ->where('entity_id', $id)
            ->orderByDesc('revision')
            ->limit(50)
            ->get()
            ->map(fn (object $row): array => $this->verify($row))
            ->all();
    }

    private function verify(object $row): array
    {
        $event = [
            'entity_type' => (string) $row->entity_type,
            'entity_id' => (int) $row->entity_id,
            'revision' => (int) $row->revision,
            'actor_id' => (int) $row->actor_id,
            'action' => (string) $row->action,
            'payload_json' => (string) $row->payload_json,
            'created_at' => CarbonImmutable::parse(
                (string) $row->created_at
            )->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ];

        if (!hash_equals(
            (string) $row->event_hmac,
            $this->signature($event)
        )) {
            throw new RuntimeException(
                'Catalog audit signature mismatch'
            );
        }

        return $event;
    }

    private function signature(array $event): string
    {
        return $this->signer->digest([
            'domain' => 'boardriders-catalog71-v1',
            ...$event,
        ]);
    }
}
