<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_financial_snapshots', function (Blueprint $table) {
            $table->id();

            // Один первинний фінансовий snapshot на одне замовлення.
            $table->foreignId('order_id')
                ->unique()
                ->constrained('orders')
                ->restrictOnDelete();

            $table->unsignedSmallInteger('schema_version');
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('goods_payable_minor');

            // Зберігаємо САМЕ ті JSON-байти, з яких обчислено SHA-256.
            // jsonb змінював би порядок ключів та вихідний запис.
            $table->longText('snapshot_json');
            $table->char('snapshot_sha256', 64);
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->foreign('currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();
        });

        // sha256(bytea) — вбудована функція PostgreSQL.
        // База перевіряє відповідність контрольної суми в момент INSERT.
        DB::statement(<<<'SQL'
            ALTER TABLE order_financial_snapshots
            ADD CONSTRAINT order_financial_snapshots_integrity_check
            CHECK (
                schema_version = 1
                AND goods_payable_minor >= 0
                AND snapshot_json IS JSON OBJECT
                AND snapshot_sha256 ~ '^[0-9a-f]{64}$'
                AND snapshot_sha256 = encode(
                    sha256(convert_to(snapshot_json, 'UTF8')),
                    'hex'
                )
                AND (snapshot_json::jsonb ->> 'schema_version')::integer
                    = schema_version
                AND (snapshot_json::jsonb ->> 'currency_code')
                    = currency_code
                AND (snapshot_json::jsonb ->> 'goods_payable_minor')::bigint
                    = goods_payable_minor
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_reject_financial_snapshot_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'order financial snapshots are immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER order_financial_snapshots_no_update_delete
            BEFORE UPDATE OR DELETE ON order_financial_snapshots
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_reject_financial_snapshot_mutation()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER order_financial_snapshots_no_truncate
            BEFORE TRUNCATE ON order_financial_snapshots
            FOR EACH STATEMENT
            EXECUTE FUNCTION boardriders_reject_financial_snapshot_mutation()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_financial_snapshots');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_reject_financial_snapshot_mutation()'
        );
    }
};
