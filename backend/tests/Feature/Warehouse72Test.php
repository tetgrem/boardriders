<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Inventory\InventoryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Warehouse72Test extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1/admin/warehouse72';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test') {
            throw new \RuntimeException(
                'Requires PostgreSQL boardriders_test'
            );
        }

        config()->set('warehouse72.enabled', true);
        config()->set('crm65.enabled', true);
    }

    private function staff(string $role = 'manager'): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        DB::table('checkout_crm_staff65')->insert([
            'user_id' => $user->id,
            'role' => $role,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken(
            $user->createToken(
                'crm65-dashboard',
                ['crm65:access']
            )->plainTextToken
        );

        return $user;
    }

    private function warehouse(
        string $kind = 'owned',
        bool $active = true
    ): int {
        return (int) DB::table('warehouses')->insertGetId([
            'code' => 'TEST-WH-' . Str::uuid(),
            'name' => 'Test warehouse',
            'country_code' => 'UA',
            'kind' => $kind,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function variant(): int
    {
        $productId = DB::table('products')->insertGetId([
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('product_variants')->insertGetId([
            'product_id' => $productId,
            'sku' => 'TEST-SKU-' . Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function receive(
        int $warehouseId,
        int $variantId,
        int $quantity
    ): void {
        app(InventoryService::class)->receiveOnce(
            $warehouseId,
            $variantId,
            $quantity,
            'WAREHOUSE72-TEST',
            (string) Str::uuid()
        );
    }

    private function stock(int $warehouse, int $variant): object
    {
        return DB::table('inventory_stocks')
            ->where('warehouse_id', $warehouse)
            ->where('variant_id', $variant)
            ->first();
    }

    private function body(
        string $kind,
        int $warehouse,
        array $lines,
        array $extra = []
    ): array {
        return [
            'operation_key' => (string) Str::uuid(),
            'kind' => $kind,
            'warehouse_id' => $warehouse,
            'reference' => 'DOC-' . Str::uuid(),
            'lines' => $lines,
            ...$extra,
        ];
    }

    private function postDocument(array $body)
    {
        return $this->postJson(
            self::API . '/submit',
            $body
        );
    }

    public function test_warehouse72_can_create_owned_warehouse(): void
    {
        $this->staff();

        $response = $this->postDocument([
            'operation_key' => (string) Str::uuid(),
            'kind' => 'warehouse_create',
            'name' => 'Main warehouse',
            'country_code' => 'UA',
            'city' => 'Черкаси',
        ])->assertCreated()
            ->assertJsonPath('result.revision', 1);

        $this->assertDatabaseHas('warehouses', [
            'id' => $response->json('result.warehouse_id'),
            'name' => 'Main warehouse',
            'kind' => 'owned',
            'is_active' => true,
        ]);
    }

    public function test_warehouse72_updates_warehouse_with_revision(): void
    {
        $this->staff();

        $warehouse = $this->warehouse();

        $this->postDocument([
            'operation_key' => (string) Str::uuid(),
            'kind' => 'warehouse_update',
            'warehouse_id' => $warehouse,
            'expected_revision' => 0,
            'name' => 'Updated warehouse',
            'country_code' => 'UA',
        ])->assertCreated()
            ->assertJsonPath('result.revision', 1);

        $this->assertDatabaseHas('warehouses', [
            'id' => $warehouse,
            'name' => 'Updated warehouse',
            'admin_revision' => 1,
        ]);
    }

    public function test_warehouse72_receipt_posts_multiple_lines(): void
    {
        $this->staff();

        $warehouse = $this->warehouse();
        $first = $this->variant();
        $second = $this->variant();

        $this->postDocument($this->body(
            'receipt',
            $warehouse,
            [
                ['variant_id' => $first, 'quantity' => 10],
                ['variant_id' => $second, 'quantity' => 20],
            ]
        ))->assertCreated()
            ->assertJsonPath('result.line_count', 2);

        $this->assertSame(
            10,
            (int) $this->stock($warehouse, $first)->on_hand_qty
        );

        $this->assertSame(
            20,
            (int) $this->stock($warehouse, $second)->on_hand_qty
        );

        $this->assertDatabaseCount(
            'checkout_warehouse_documents72',
            1
        );

        $this->assertDatabaseCount('inventory_receipts', 2);
    }

    public function test_warehouse72_repeated_document_is_idempotent(): void
    {
        $this->staff();

        $warehouse = $this->warehouse();
        $variant = $this->variant();

        $body = $this->body('receipt', $warehouse, [
            ['variant_id' => $variant, 'quantity' => 10],
        ]);

        $first = $this->postDocument($body)
            ->assertCreated()
            ->assertJsonPath('replayed', false)
            ->json('document_id');

        $this->postDocument($body)
            ->assertCreated()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('document_id', $first);

        $this->assertSame(
            10,
            (int) $this->stock($warehouse, $variant)->on_hand_qty
        );

        $this->assertDatabaseCount('inventory_receipts', 1);
    }

    public function test_warehouse72_cannot_reuse_key_with_changed_payload(): void
    {
        $this->staff();

        $warehouse = $this->warehouse();
        $variant = $this->variant();

        $body = $this->body('receipt', $warehouse, [
            ['variant_id' => $variant, 'quantity' => 10],
        ]);

        $this->postDocument($body)->assertCreated();

        $body['lines'][0]['quantity'] = 20;

        $this->postDocument($body)->assertStatus(409);

        $this->assertSame(
            10,
            (int) $this->stock($warehouse, $variant)->on_hand_qty
        );
    }

    public function test_warehouse72_duplicate_document_number_is_rejected(): void
    {
        $this->staff();

        $warehouse = $this->warehouse();
        $variant = $this->variant();

        $body = $this->body('receipt', $warehouse, [
            ['variant_id' => $variant, 'quantity' => 5],
        ]);

        $this->postDocument($body)->assertCreated();

        $body['operation_key'] = (string) Str::uuid();

        $this->postDocument($body)->assertStatus(409);

        $this->assertDatabaseCount('inventory_receipts', 1);
    }

    public function test_warehouse72_transfers_stock(): void
    {
        $this->staff();

        $source = $this->warehouse();
        $target = $this->warehouse();
        $variant = $this->variant();

        $this->receive($source, $variant, 15);

        $this->postDocument($this->body(
            'transfer',
            $source,
            [['variant_id' => $variant, 'quantity' => 6]],
            ['to_warehouse_id' => $target]
        ))->assertCreated();

        $this->assertSame(
            9,
            (int) $this->stock($source, $variant)->on_hand_qty
        );

        $this->assertSame(
            6,
            (int) $this->stock($target, $variant)->on_hand_qty
        );
    }

    public function test_warehouse72_failed_transfer_rolls_back_all_lines(): void
    {
        $this->staff();

        $source = $this->warehouse();
        $target = $this->warehouse();

        $first = $this->variant();
        $second = $this->variant();

        $this->receive($source, $first, 10);
        $this->receive($source, $second, 1);

        $this->postDocument($this->body(
            'transfer',
            $source,
            [
                ['variant_id' => $first, 'quantity' => 5],
                ['variant_id' => $second, 'quantity' => 2],
            ],
            ['to_warehouse_id' => $target]
        ))->assertStatus(409);

        $this->assertSame(
            10,
            (int) $this->stock($source, $first)->on_hand_qty
        );

        $this->assertDatabaseMissing('inventory_stocks', [
            'warehouse_id' => $target,
            'variant_id' => $first,
        ]);

        $this->assertDatabaseCount('inventory_transfers', 0);

        $this->assertDatabaseCount(
            'checkout_warehouse_documents72',
            0
        );
    }

    public function test_warehouse72_cannot_transfer_reserved_stock(): void
    {
        $this->staff();

        $source = $this->warehouse();
        $target = $this->warehouse();
        $variant = $this->variant();

        $this->receive($source, $variant, 10);

        app(InventoryService::class)->reserveForOrder(
            $source,
            $variant,
            8,
            'RESERVE-TEST',
            (string) Str::uuid()
        );

        $this->postDocument($this->body(
            'transfer',
            $source,
            [['variant_id' => $variant, 'quantity' => 3]],
            ['to_warehouse_id' => $target]
        ))->assertStatus(409);
    }

    public function test_warehouse72_stocktake_sets_counted_quantity(): void
    {
        $this->staff();

        $warehouse = $this->warehouse();
        $variant = $this->variant();

        $this->receive($warehouse, $variant, 10);

        $this->postDocument($this->body('stocktake', $warehouse, [
            [
                'variant_id' => $variant,
                'quantity' => 7,
                'expected_revision' => (int) $this->stock(
                    $warehouse, $variant
                )->revision,
            ],
        ]))->assertCreated();

        $this->assertSame(
            7,
            (int) $this->stock($warehouse, $variant)->on_hand_qty
        );
    }

    public function test_warehouse72_stale_stocktake_is_rejected(): void
    {
        $this->staff();

        $warehouse = $this->warehouse();
        $variant = $this->variant();

        $this->receive($warehouse, $variant, 10);

        $revision = (int) $this->stock(
            $warehouse,
            $variant
        )->revision;

        $this->receive($warehouse, $variant, 2);

        $this->postDocument($this->body('stocktake', $warehouse, [
            [
                'variant_id' => $variant,
                'quantity' => 7,
                'expected_revision' => $revision,
            ],
        ]))->assertStatus(409);

        $this->assertSame(
            12,
            (int) $this->stock($warehouse, $variant)->on_hand_qty
        );
    }

    public function test_warehouse72_writeoff_reduces_physical_stock(): void
    {
        $this->staff();

        $warehouse = $this->warehouse();
        $variant = $this->variant();

        $this->receive($warehouse, $variant, 10);

        $this->postDocument($this->body(
            'writeoff',
            $warehouse,
            [[
                'variant_id' => $variant,
                'quantity' => 3,
                'expected_revision' => (int) $this->stock(
                    $warehouse, $variant
                )->revision,
            ]],
            [
                'reason' => 'damage',
                'note' => 'Damaged units',
            ]
        ))->assertCreated();

        $this->assertSame(
            7,
            (int) $this->stock($warehouse, $variant)->on_hand_qty
        );

        $this->assertDatabaseHas('inventory_adjustments', [
            'reason' => 'damage',
            'quantity_delta' => -3,
        ]);
    }

    public function test_warehouse72_writeoff_cannot_consume_reservation(): void
    {
        $this->staff();

        $warehouse = $this->warehouse();
        $variant = $this->variant();

        $this->receive($warehouse, $variant, 10);

        app(InventoryService::class)->reserveForOrder(
            $warehouse,
            $variant,
            8,
            'RESERVE-TEST',
            (string) Str::uuid()
        );

        $this->postDocument($this->body(
            'writeoff',
            $warehouse,
            [[
                'variant_id' => $variant,
                'quantity' => 3,
                'expected_revision' => (int) $this->stock(
                    $warehouse, $variant
                )->revision,
            ]],
            [
                'reason' => 'loss',
                'note' => 'Missing units',
            ]
        ))->assertStatus(409);

        $this->assertSame(
            8,
            (int) $this->stock($warehouse, $variant)->reserved_qty
        );
    }

    public function test_warehouse72_partner_warehouse_is_read_only(): void
    {
        $this->staff();

        $warehouse = $this->warehouse('partner');
        $variant = $this->variant();

        $this->postDocument($this->body(
            'receipt',
            $warehouse,
            [['variant_id' => $variant, 'quantity' => 1]]
        ))->assertStatus(409);
    }

    public function test_warehouse72_inactive_warehouse_is_rejected(): void
    {
        $this->staff();

        $warehouse = $this->warehouse('owned', false);
        $variant = $this->variant();

        $this->postDocument($this->body(
            'receipt',
            $warehouse,
            [['variant_id' => $variant, 'quantity' => 1]]
        ))->assertStatus(409);
    }

    public function test_warehouse72_lookup_includes_variant_without_stock(): void
    {
        $this->staff();

        $warehouse = $this->warehouse();
        $variant = $this->variant();

        $sku = DB::table('product_variants')
            ->where('id', $variant)
            ->value('sku');

        $this->getJson(
            self::API . "/variants?warehouse_id={$warehouse}"
            . '&q=' . urlencode($sku)
        )->assertOk()
            ->assertJsonPath('items.0.id', $variant)
            ->assertJsonPath('items.0.on_hand_qty', 0)
            ->assertJsonPath('items.0.revision', 0);
    }

    public function test_warehouse72_overview_separates_reserved_and_available(): void
    {
        $this->staff();

        $warehouse = $this->warehouse();
        $variant = $this->variant();

        $this->receive($warehouse, $variant, 10);

        app(InventoryService::class)->reserveForOrder(
            $warehouse,
            $variant,
            4,
            'RESERVE-TEST',
            (string) Str::uuid()
        );

        $this->getJson(self::API . '/overview')
            ->assertOk()
            ->assertJsonPath('summary.on_hand', 10)
            ->assertJsonPath('summary.reserved', 4)
            ->assertJsonPath('summary.available', 6);

        $this->getJson(
            self::API . "/stocks?warehouse_id={$warehouse}"
        )->assertOk()
            ->assertJsonPath('items.0.reserve_consistent', true);

        $this->getJson(
            self::API . "/reservations?warehouse_id={$warehouse}"
        )->assertOk()
            ->assertJsonPath('items.0.active_qty', 4);
    }

    public function test_warehouse72_document_history_is_readable(): void
    {
        $this->staff();

        $warehouse = $this->warehouse();
        $variant = $this->variant();

        $id = $this->postDocument($this->body(
            'receipt',
            $warehouse,
            [['variant_id' => $variant, 'quantity' => 5]]
        ))->assertCreated()->json('document_id');

        $this->getJson(self::API . '/documents')
            ->assertOk()
            ->assertJsonPath('items.0.id', $id);

        $this->getJson(self::API . "/documents/{$id}")
            ->assertOk()
            ->assertJsonPath('result.lines.0.quantity', 5);

        $this->getJson(self::API . '/movements')
            ->assertOk()
            ->assertJsonPath('items.0.quantity_delta', 5);
    }

    public function test_warehouse72_document_cannot_be_deleted(): void
    {
        $this->staff();

        $warehouse = $this->warehouse();
        $variant = $this->variant();

        $this->postDocument($this->body(
            'receipt',
            $warehouse,
            [['variant_id' => $variant, 'quantity' => 1]]
        ))->assertCreated();

        $this->expectException(QueryException::class);

        DB::table('checkout_warehouse_documents72')->delete();
    }

    public function test_warehouse72_support_has_read_only_access(): void
    {
        $this->staff('support');

        $this->getJson(self::API . '/overview')->assertOk();

        $this->postDocument([
            'operation_key' => (string) Str::uuid(),
            'kind' => 'warehouse_create',
            'name' => 'Forbidden',
            'country_code' => 'UA',
        ])->assertForbidden();
    }

    public function test_warehouse72_viewer_has_no_warehouse_access(): void
    {
        $this->staff('viewer');

        $this->getJson(self::API . '/overview')
            ->assertForbidden();
    }

    public function test_warehouse72_disabled_flag_is_enforced(): void
    {
        $this->staff();

        config()->set('warehouse72.enabled', false);

        $this->getJson(self::API . '/overview')
            ->assertStatus(503);
    }

    public function test_warehouse72_requires_authentication(): void
    {
        $this->getJson(self::API . '/overview')
            ->assertUnauthorized();
    }
}
