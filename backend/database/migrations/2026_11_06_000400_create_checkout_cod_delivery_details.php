<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_recipients', function (Blueprint $table) {
            $table->id();
            $table->uuid('recipient_key')->unique();
            $table->foreignId('order_id')->unique()
                ->constrained('orders')->restrictOnDelete();
            $table->foreignId('cod_delivery_selection_id')
                ->constrained('checkout_cod_delivery_selections')
                ->restrictOnDelete();
            $table->uuid('city_ref');
            $table->text('first_name_ciphertext');
            $table->text('last_name_ciphertext');
            $table->text('middle_name_ciphertext')->nullable();
            $table->text('phone_ciphertext');
            $table->char('request_hmac', 64);
            $table->timestampTz('checkout_expires_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(
                'cod_delivery_selection_id',
                'cod_recipients_selection_idx'
            );
        });

        Schema::create('checkout_cod_shipping_quotes', function (Blueprint $table) {
            $table->id();
            $table->uuid('quote_key')->unique();
            $table->foreignId('order_id')->unique()
                ->constrained('orders')->restrictOnDelete();
            $table->foreignId('recipient_id')
                ->constrained('checkout_cod_recipients')->restrictOnDelete();
            $table->foreignId('cod_delivery_selection_id')
                ->constrained('checkout_cod_delivery_selections')
                ->restrictOnDelete();

            $table->char('request_hmac', 64);
            $table->unsignedInteger('weight_grams');
            $table->unsignedSmallInteger('seats_amount');
            $table->unsignedBigInteger('goods_due_minor');
            $table->char('currency_code', 3);
            $table->char('snapshot_sha256', 64);
            $table->unsignedBigInteger('delivery_cost_minor');
            $table->unsignedBigInteger('cod_transfer_fee_minor')->nullable();
            $table->boolean('cod_fee_available');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('recipient_id', 'cod_quotes_recipient_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_shipping_quotes
            ADD CONSTRAINT cod_quotes_valid_amounts CHECK (
                weight_grams BETWEEN 1 AND 10000000
                AND seats_amount BETWEEN 1 AND 20
                AND goods_due_minor BETWEEN 100 AND 100000000
                AND delivery_cost_minor <= 100000000
                AND (
                    cod_transfer_fee_minor IS NULL
                    OR cod_transfer_fee_minor <= 100000000
                )
                AND (
                    cod_fee_available = (cod_transfer_fee_minor IS NOT NULL)
                )
                AND currency_code = 'UAH'
                AND snapshot_sha256 ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION checkout_cod_delivery_details_guard_insert()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                selection_row RECORD;
                recipient_row RECORD;
            BEGIN
                SELECT s.order_id, s.carrier_code, s.checkout_expires_at,
                       s.goods_due_minor, s.currency_code, s.snapshot_sha256,
                       o.payment_status
                INTO selection_row
                FROM checkout_cod_delivery_selections s
                JOIN orders o ON o.id = s.order_id
                WHERE s.id = NEW.cod_delivery_selection_id;

                IF NOT FOUND
                    OR selection_row.order_id <> NEW.order_id
                    OR selection_row.carrier_code <> 'nova_poshta'
                    OR selection_row.payment_status <> 'pending'
                    OR selection_row.checkout_expires_at <= CURRENT_TIMESTAMP
                THEN
                    RAISE EXCEPTION
                        'COD delivery details: invalid order or selection';
                END IF;

                IF TG_TABLE_NAME = 'checkout_cod_recipients' THEN
                    IF NEW.checkout_expires_at <>
                       selection_row.checkout_expires_at
                    THEN
                        RAISE EXCEPTION 'COD recipient: expiration mismatch';
                    END IF;
                ELSE
                    SELECT r.order_id, r.cod_delivery_selection_id
                    INTO recipient_row
                    FROM checkout_cod_recipients r
                    WHERE r.id = NEW.recipient_id;

                    IF NOT FOUND
                       OR recipient_row.order_id <> NEW.order_id
                       OR recipient_row.cod_delivery_selection_id <>
                          NEW.cod_delivery_selection_id
                       OR NEW.goods_due_minor <> selection_row.goods_due_minor
                       OR NEW.currency_code <> selection_row.currency_code
                       OR NEW.snapshot_sha256 <> selection_row.snapshot_sha256
                    THEN
                        RAISE EXCEPTION
                            'COD shipping quote: inconsistent financial binding';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION checkout_cod_delivery_details_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD delivery details are immutable';
            END;
            $$
        SQL);

        foreach ([
                     'checkout_cod_recipients',
                     'checkout_cod_shipping_quotes',
                 ] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_insert_guard
                 BEFORE INSERT ON {$table}
                 FOR EACH ROW
                 EXECUTE FUNCTION checkout_cod_delivery_details_guard_insert()"
            );

            DB::statement(
                "CREATE TRIGGER {$table}_immutable
                 BEFORE UPDATE OR DELETE ON {$table}
                 FOR EACH ROW
                 EXECUTE FUNCTION checkout_cod_delivery_details_immutable()"
            );

            DB::statement(
                "CREATE TRIGGER {$table}_no_truncate
                 BEFORE TRUNCATE ON {$table}
                 FOR EACH STATEMENT
                 EXECUTE FUNCTION checkout_cod_delivery_details_immutable()"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_shipping_quotes');
        Schema::dropIfExists('checkout_cod_recipients');

        DB::statement(
            'DROP FUNCTION IF EXISTS checkout_cod_delivery_details_guard_insert()'
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS checkout_cod_delivery_details_immutable()'
        );
    }
};
