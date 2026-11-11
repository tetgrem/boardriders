<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_bank_evidence_nonces', function (Blueprint $t) {
            $t->id();
            $t->string('provider_id', 32);
            $t->uuid('nonce');
            $t->char('body_sha256', 64);
            $t->timestampTz('received_at', 6)->useCurrent();
            $t->unique(['provider_id', 'nonce'], 'cod_bank_evidence_nonce_unique');
        });

        Schema::create('checkout_cod_bank_evidence_events', function (Blueprint $t) {
            $t->id();
            $t->string('provider_id', 32);
            $t->string('key_id', 32);
            $t->uuid('event_key');
            $t->foreignId('nonce_id')->unique()
                ->constrained('checkout_cod_bank_evidence_nonces')->restrictOnDelete();
            $t->char('reference_hmac', 64);
            $t->char('transaction_hmac', 64);
            $t->char('reverses_transaction_hmac', 64)->nullable();
            $t->string('account_alias', 48);
            $t->string('event_kind', 16);
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency_code', 3);
            $t->unsignedBigInteger('occurred_at_epoch');
            $t->unsignedBigInteger('signed_at_epoch');
            $t->char('body_sha256', 64);
            $t->timestampTz('received_at', 6)->useCurrent();
            $t->unique(['provider_id', 'event_key'], 'cod_bank_evidence_event_unique');
            $t->unique(['provider_id', 'transaction_hmac'], 'cod_bank_evidence_tx_unique');
            $t->index(['reference_hmac', 'id'], 'cod_bank_evidence_reference_history');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_bank_evidence_nonces
            ADD CONSTRAINT cod_bank_evidence_nonce_values CHECK (
                provider_id ~ '^[a-z][a-z0-9_-]{0,31}$'
                AND body_sha256 ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_bank_evidence_events
            ADD CONSTRAINT cod_bank_evidence_event_values CHECK (
                provider_id ~ '^[a-z][a-z0-9_-]{0,31}$'
                AND key_id ~ '^[a-z][a-z0-9_-]{0,31}$'
                AND account_alias ~ '^[a-z][a-z0-9_-]{0,47}$'
                AND reference_hmac ~ '^[a-f0-9]{64}$'
                AND transaction_hmac ~ '^[a-f0-9]{64}$'
                AND (reverses_transaction_hmac IS NULL OR reverses_transaction_hmac ~ '^[a-f0-9]{64}$')
                AND body_sha256 ~ '^[a-f0-9]{64}$'
                AND currency_code = 'UAH'
                AND amount_minor BETWEEN 1 AND 100000000
                AND occurred_at_epoch BETWEEN 1000000000 AND 9999999999
                AND signed_at_epoch BETWEEN 1000000000 AND 9999999999
                AND (
                    (event_kind = 'credit' AND reverses_transaction_hmac IS NULL)
                    OR (event_kind = 'reversal' AND reverses_transaction_hmac IS NOT NULL
                        AND reverses_transaction_hmac <> transaction_hmac)
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_bank_evidence_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_ref CHAR(64);
                v_amount BIGINT;
                v_credits INTEGER;
                v_reversals INTEGER;
                v_mismatches INTEGER;
            BEGIN
                IF TG_TABLE_NAME = 'checkout_cod_settlement_bank_matches' THEN
                    SELECT reference_hmac, claimed_net_minor INTO v_ref, v_amount
                    FROM checkout_cod_settlement_bank_receipts
                    WHERE id = NEW.bank_receipt_id;
                ELSIF TG_TABLE_NAME = 'checkout_cod_settlement_bank_reviews' THEN
                    SELECT b.reference_hmac, b.claimed_net_minor INTO v_ref, v_amount
                    FROM checkout_cod_settlement_bank_matches m
                    JOIN checkout_cod_settlement_bank_receipts b ON b.id = m.bank_receipt_id
                    WHERE m.id = NEW.match_id;
                ELSIF TG_TABLE_NAME IN (
                    'checkout_cod_bank_batch_allocations',
                    'checkout_cod_bank_batch_reviews'
                ) THEN
                    SELECT reference_hmac, claimed_net_minor INTO v_ref, v_amount
                    FROM checkout_cod_bank_batches WHERE id = NEW.batch_id;
                END IF;

                IF v_ref IS NOT NULL THEN
                    PERFORM 1 FROM checkout_cod_bank_reference_claims
                    WHERE reference_hmac = v_ref FOR UPDATE;

                    SELECT COUNT(*) FILTER (WHERE event_kind = 'credit'),
                           COUNT(*) FILTER (WHERE event_kind = 'reversal'),
                           COUNT(*) FILTER (WHERE event_kind = 'credit'
                               AND amount_minor <> v_amount)
                    INTO v_credits, v_reversals, v_mismatches
                    FROM checkout_cod_bank_evidence_events
                    WHERE reference_hmac = v_ref;

                    IF v_reversals > 0 OR v_mismatches > 0 OR v_credits > 1 THEN
                        RAISE EXCEPTION
                            'Bank signed evidence requires independent investigation';
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
                CREATE TRIGGER zzzz_cod_bank_evidence_guard
                BEFORE INSERT ON {$table}
                FOR EACH ROW EXECUTE FUNCTION boardriders_cod_bank_evidence_guard()
            ");
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_bank_evidence_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Signed bank evidence history is immutable';
            END;
            $$
        SQL);

        foreach (['checkout_cod_bank_evidence_nonces', 'checkout_cod_bank_evidence_events'] as $table) {
            DB::statement("
                CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION boardriders_cod_bank_evidence_immutable()
            ");
            DB::statement("
                CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_bank_evidence_immutable()
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
            DB::statement("DROP TRIGGER IF EXISTS zzzz_cod_bank_evidence_guard ON {$table}");
        }

        Schema::dropIfExists('checkout_cod_bank_evidence_events');
        Schema::dropIfExists('checkout_cod_bank_evidence_nonces');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_bank_evidence_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_bank_evidence_immutable()');
    }
};
