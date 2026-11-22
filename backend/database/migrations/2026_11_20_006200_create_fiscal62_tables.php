<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_fiscal_profiles62', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('variant_id')->unique()->constrained('product_variants')->restrictOnDelete();
            $t->string('fiscal_code', 40);
            $t->string('fiscal_name', 200);
            $t->unsignedSmallInteger('tax_code');
            $t->unsignedBigInteger('approved_by');
            $t->char('profile_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
        });

        Schema::create('checkout_fiscal_tickets62', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('document_id')->unique()
                ->constrained('checkout_fiscal_documents61')->restrictOnDelete();
            $t->uuid('provider_receipt_id')->unique();
            $t->uuid('related_receipt_id')->nullable();
            $t->longText('request_json');
            $t->char('request_sha256', 64);
            $t->char('identity_hmac', 64);
            $t->unsignedBigInteger('prepared_by');
            $t->string('status', 24)->default('prepared');
            $t->timestampTz('attempted_at', 6)->nullable();
            $t->string('fiscal_code', 100)->nullable();
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->timestampTz('updated_at', 6)->useCurrent();
        });

        Schema::create('checkout_fiscal_events62', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('ticket_id')->constrained('checkout_fiscal_tickets62')->restrictOnDelete();
            $t->string('kind', 32);
            $t->string('detail', 100);
            $t->unsignedBigInteger('actor_id');
            $t->char('event_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->index(['ticket_id', 'id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_fiscal_tickets62
            ADD CONSTRAINT fiscal62_ticket_state CHECK (
                status IN ('prepared','transmitting','uncertain','provider_pending','needs_review','done')
                AND (
                    (status = 'done' AND fiscal_code IS NOT NULL)
                    OR (status <> 'done' AND fiscal_code IS NULL)
                )
                AND (
                    (status = 'prepared' AND attempted_at IS NULL)
                    OR (status <> 'prepared' AND attempted_at IS NOT NULL)
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION fiscal62_ticket_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Fiscal ticket deletion forbidden';
                END IF;

                IF (
                    NEW.document_id,
                    NEW.provider_receipt_id,
                    NEW.related_receipt_id,
                    NEW.request_json,
                    NEW.request_sha256,
                    NEW.identity_hmac,
                    NEW.prepared_by,
                    NEW.created_at
                ) IS DISTINCT FROM (
                    OLD.document_id,
                    OLD.provider_receipt_id,
                    OLD.related_receipt_id,
                    OLD.request_json,
                    OLD.request_sha256,
                    OLD.identity_hmac,
                    OLD.prepared_by,
                    OLD.created_at
                ) THEN
                    RAISE EXCEPTION 'Fiscal ticket identity immutable';
                END IF;

                IF OLD.status = 'done'
                    OR (
                        OLD.attempted_at IS NOT NULL
                        AND NEW.attempted_at IS DISTINCT FROM OLD.attempted_at
                    ) THEN
                    RAISE EXCEPTION 'Fiscal sent identity cannot reset';
                END IF;

                IF NOT (
                    (
                        OLD.status = 'prepared'
                        AND NEW.status = 'transmitting'
                        AND NEW.attempted_at IS NOT NULL
                    )
                    OR (
                        OLD.status = 'transmitting'
                        AND NEW.status IN ('uncertain','needs_review')
                    )
                    OR (
                        OLD.status IN ('uncertain','provider_pending','needs_review')
                        AND NEW.status IN ('provider_pending','needs_review','done')
                    )
                ) THEN
                    RAISE EXCEPTION 'Invalid fiscal transition';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER fiscal62_ticket_update
            BEFORE UPDATE OR DELETE ON checkout_fiscal_tickets62
            FOR EACH ROW
            EXECUTE FUNCTION fiscal62_ticket_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION fiscal62_append_only()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Fiscal62CheckboxClient.php record is immutable';
            END;
            $$
        SQL);

        foreach ([
                     'checkout_fiscal_profiles62',
                     'checkout_fiscal_events62',
                 ] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_immutable
                 BEFORE UPDATE OR DELETE ON {$table}
                 FOR EACH ROW
                 EXECUTE FUNCTION fiscal62_append_only()"
            );
        }

        foreach ([
                     'checkout_fiscal_profiles62',
                     'checkout_fiscal_events62',
                     'checkout_fiscal_tickets62',
                 ] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_no_truncate
                 BEFORE TRUNCATE ON {$table}
                 FOR EACH STATEMENT
                 EXECUTE FUNCTION fiscal62_append_only()"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_fiscal_events62');
        Schema::dropIfExists('checkout_fiscal_tickets62');
        Schema::dropIfExists('checkout_fiscal_profiles62');

        DB::statement('DROP FUNCTION IF EXISTS fiscal62_ticket_guard()');
        DB::statement('DROP FUNCTION IF EXISTS fiscal62_append_only()');
    }
};
