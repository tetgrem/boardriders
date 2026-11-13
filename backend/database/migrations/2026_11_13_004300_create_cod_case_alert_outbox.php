<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(
            'checkout_cod_case_alert_scan_cursor',
            function (Blueprint $t): void {
                $t->string('cursor_name', 32)->primary();
                $t->unsignedBigInteger('last_case_id')->nullable();
                $t->timestampTz('updated_at', 6)->useCurrent();
            }
        );

        DB::table('checkout_cod_case_alert_scan_cursor')->insert([
            'cursor_name' => 'sla_v43',
            'last_case_id' => null,
            'updated_at' => now(),
        ]);

        Schema::create(
            'checkout_cod_case_alert_outbox',
            function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('case_id');

                $t->foreign('case_id', 'cod_alert_case_fk')
                    ->references('id')
                    ->on('checkout_cod_operations_cases')
                    ->restrictOnDelete();

                $t->unsignedInteger('case_revision');
                $t->string('alert_kind', 24);

                // NULL means team inbox.
                $t->unsignedBigInteger('recipient_id')->nullable();

                $t->timestampTz('next_dispatch_at', 6)
                    ->useCurrent();

                $t->timestampTz('delivered_at', 6)->nullable();
                $t->timestampTz('suppressed_at', 6)->nullable();
                $t->timestampTz('created_at', 6)->useCurrent();

                $t->unique(
                    ['case_id', 'case_revision', 'alert_kind'],
                    'cod_alert_revision_once'
                );

                $t->index(
                    [
                        'delivered_at',
                        'suppressed_at',
                        'next_dispatch_at',
                        'id',
                    ],
                    'cod_alert_dispatch_due'
                );
            }
        );

        Schema::create(
            'checkout_cod_case_alert_inbox',
            function (Blueprint $t): void {
                $t->id();

                $t->unsignedBigInteger('outbox_id')
                    ->unique('cod_inbox_outbox_once');

                $t->foreign('outbox_id', 'cod_inbox_outbox_fk')
                    ->references('id')
                    ->on('checkout_cod_case_alert_outbox')
                    ->restrictOnDelete();

                $t->unsignedBigInteger('case_id');

                $t->foreign('case_id', 'cod_inbox_case_fk')
                    ->references('id')
                    ->on('checkout_cod_operations_cases')
                    ->restrictOnDelete();

                $t->unsignedBigInteger('recipient_id')->nullable();
                $t->string('alert_kind', 24);

                $t->timestampTz('read_at', 6)->nullable();
                $t->unsignedBigInteger('read_by')->nullable();

                $t->timestampTz('created_at', 6)->useCurrent();

                $t->index(
                    ['recipient_id', 'id'],
                    'cod_inbox_recipient_queue'
                );

                $t->index(
                    ['read_at', 'id'],
                    'cod_inbox_unread_queue'
                );
            }
        );

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_case_alert_outbox
            ADD CONSTRAINT cod_alert_outbox_values CHECK (
                case_revision > 0
                AND alert_kind IN (
                    'unassigned_30m',
                    'due_soon',
                    'overdue',
                    'overdue_4h',
                    'overdue_24h'
                )
                AND (recipient_id IS NULL OR recipient_id > 0)
                AND NOT (
                    delivered_at IS NOT NULL
                    AND suppressed_at IS NOT NULL
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_case_alert_inbox
            ADD CONSTRAINT cod_alert_inbox_values CHECK (
                alert_kind IN (
                    'unassigned_30m',
                    'due_soon',
                    'overdue',
                    'overdue_4h',
                    'overdue_24h'
                )
                AND (recipient_id IS NULL OR recipient_id > 0)
                AND (
                    (read_at IS NULL AND read_by IS NULL)
                    OR (read_at IS NOT NULL AND read_by > 0)
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION
            boardriders_cod_alert_outbox_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.id <> OLD.id
                    OR NEW.case_id <> OLD.case_id
                    OR NEW.case_revision <> OLD.case_revision
                    OR NEW.alert_kind <> OLD.alert_kind
                    OR NEW.recipient_id IS DISTINCT FROM OLD.recipient_id
                    OR NEW.created_at <> OLD.created_at
                    OR (
                        OLD.delivered_at IS NOT NULL
                        AND NEW.delivered_at
                            IS DISTINCT FROM OLD.delivered_at
                    )
                    OR (
                        OLD.suppressed_at IS NOT NULL
                        AND NEW.suppressed_at
                            IS DISTINCT FROM OLD.suppressed_at
                    )
                THEN
                    RAISE EXCEPTION
                        'COD alert outbox identity or terminal state immutable';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_alert_outbox_guard
            BEFORE UPDATE
            ON checkout_cod_case_alert_outbox
            FOR EACH ROW
            EXECUTE FUNCTION
                boardriders_cod_alert_outbox_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION
            boardriders_cod_alert_inbox_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.id <> OLD.id
                    OR NEW.outbox_id <> OLD.outbox_id
                    OR NEW.case_id <> OLD.case_id
                    OR NEW.alert_kind <> OLD.alert_kind
                    OR NEW.recipient_id IS DISTINCT FROM OLD.recipient_id
                    OR NEW.created_at <> OLD.created_at
                    OR (
                        OLD.read_at IS NOT NULL
                        AND (
                            NEW.read_at IS DISTINCT FROM OLD.read_at
                            OR NEW.read_by IS DISTINCT FROM OLD.read_by
                        )
                    )
                THEN
                    RAISE EXCEPTION
                        'COD alert inbox identity/receipt immutable';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_alert_inbox_guard
            BEFORE UPDATE
            ON checkout_cod_case_alert_inbox
            FOR EACH ROW
            EXECUTE FUNCTION
                boardriders_cod_alert_inbox_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION
            boardriders_cod_alert_no_delete()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION
                    'COD alert evidence cannot be deleted or truncated';
            END;
            $$
        SQL);

        foreach ([
                     'checkout_cod_case_alert_outbox',
                     'checkout_cod_case_alert_inbox',
                 ] as $table) {
            DB::statement("
                CREATE TRIGGER {$table}_no_delete
                BEFORE DELETE ON {$table}
                FOR EACH ROW
                EXECUTE FUNCTION boardriders_cod_alert_no_delete()
            ");

            DB::statement("
                CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT
                EXECUTE FUNCTION boardriders_cod_alert_no_delete()
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_case_alert_inbox');
        Schema::dropIfExists('checkout_cod_case_alert_outbox');
        Schema::dropIfExists('checkout_cod_case_alert_scan_cursor');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_alert_outbox_guard()'
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_alert_inbox_guard()'
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_alert_no_delete()'
        );
    }
};
