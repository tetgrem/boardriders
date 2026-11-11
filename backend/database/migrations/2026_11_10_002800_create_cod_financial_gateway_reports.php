<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_gateway_nonces', function (Blueprint $table) {
            $table->id();
            $table->string('key_id', 32);
            $table->uuid('nonce');
            $table->char('body_sha256', 64);
            $table->timestampTz('received_at', 6)->useCurrent();
            $table->unique(['key_id', 'nonce'], 'cod_gateway_nonce_once');
        });

        Schema::create('checkout_cod_gateway_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_key')->unique();
            $table->foreignId('nonce_id')->unique()
                ->constrained('checkout_cod_gateway_nonces')->restrictOnDelete();
            $table->string('key_id', 32);
            $table->char('reference_hmac', 64);
            $table->string('event_kind', 32);
            $table->unsignedBigInteger('net_minor');
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('signed_at_epoch');
            $table->char('body_sha256', 64);
            $table->timestampTz('received_at', 6)->useCurrent();
            $table->index(['reference_hmac', 'id'], 'cod_gateway_reference_history');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_gateway_nonces
            ADD CONSTRAINT cod_gateway_nonce_values CHECK (
                key_id ~ '^[a-z][a-z0-9_-]{0,31}$'
                AND body_sha256 ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_gateway_reports
            ADD CONSTRAINT cod_gateway_report_values CHECK (
                key_id ~ '^[a-z][a-z0-9_-]{0,31}$'
                AND reference_hmac ~ '^[a-f0-9]{64}$'
                AND body_sha256 ~ '^[a-f0-9]{64}$'
                AND event_kind IN ('credit_reported', 'reversal_reported')
                AND currency_code = 'UAH'
                AND net_minor BETWEEN 1 AND 100000000
                AND signed_at_epoch BETWEEN 1000000000 AND 9999999999
            )
        SQL);

        // A signed reversal report pauses matching and allocation.
        // It is NOT proof that the bank reversed a payment.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_gateway_reversal_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_ref CHAR(64);
            BEGIN
                IF TG_TABLE_NAME = 'checkout_cod_settlement_bank_matches' THEN
                    SELECT reference_hmac INTO v_ref
                    FROM checkout_cod_settlement_bank_receipts
                    WHERE id = NEW.bank_receipt_id;

                ELSIF TG_TABLE_NAME = 'checkout_cod_settlement_bank_reviews' THEN
                    SELECT b.reference_hmac INTO v_ref
                    FROM checkout_cod_settlement_bank_matches m
                    JOIN checkout_cod_settlement_bank_receipts b
                        ON b.id = m.bank_receipt_id
                    WHERE m.id = NEW.match_id;

                ELSIF TG_TABLE_NAME IN (
                    'checkout_cod_bank_batch_allocations',
                    'checkout_cod_bank_batch_reviews'
                ) THEN
                    SELECT reference_hmac INTO v_ref
                    FROM checkout_cod_bank_batches
                    WHERE id = NEW.batch_id;
                END IF;

                IF v_ref IS NOT NULL THEN
                    PERFORM 1 FROM checkout_cod_bank_reference_claims
                    WHERE reference_hmac = v_ref FOR UPDATE;

                    IF EXISTS (
                        SELECT 1 FROM checkout_cod_gateway_reports
                        WHERE reference_hmac = v_ref
                          AND event_kind = 'reversal_reported'
                    ) THEN
                        RAISE EXCEPTION
                            'Signed reversal report requires investigation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        foreach ([
                     'checkout_cod_settlement_bank_matches',
                     'checkout_cod_settlement_bank_reviews',
                     'checkout_cod_bank_batch_allocations',
                     'checkout_cod_bank_batch_reviews',
                 ] as $table) {
            DB::statement("
                CREATE TRIGGER zzz_cod_gateway_reversal_guard
                BEFORE INSERT ON {$table}
                FOR EACH ROW EXECUTE FUNCTION boardriders_cod_gateway_reversal_guard()
            ");
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_gateway_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD gateway reports and nonces are immutable';
            END;
            $$
        SQL);

        foreach ([
                     'checkout_cod_gateway_nonces',
                     'checkout_cod_gateway_reports',
                 ] as $table) {
            DB::statement("
                CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION boardriders_cod_gateway_immutable()
            ");

            DB::statement("
                CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_gateway_immutable()
            ");
        }
    }

    public function down(): void
    {
        foreach ([
                     'checkout_cod_settlement_bank_matches',
                     'checkout_cod_settlement_bank_reviews',
                     'checkout_cod_bank_batch_allocations',
                     'checkout_cod_bank_batch_reviews',
                 ] as $table) {
            DB::statement("
                DROP TRIGGER IF EXISTS zzz_cod_gateway_reversal_guard ON {$table}
            ");
        }

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_gateway_reversal_guard()'
        );

        Schema::dropIfExists('checkout_cod_gateway_reports');
        Schema::dropIfExists('checkout_cod_gateway_nonces');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_gateway_immutable()'
        );
    }
};
