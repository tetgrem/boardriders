<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_operations_cases', function (Blueprint $t): void {
            $t->id();

            $t->uuid('case_key')->unique('cod_case_key_once');

            $t->unsignedBigInteger('bank_posting_id')
                ->unique('cod_case_posting_once');

            $t->foreign('bank_posting_id', 'cod_case_posting_fk')
                ->references('id')
                ->on('checkout_cod_bank_receipt_postings')
                ->restrictOnDelete();

            $t->string('case_status', 32);
            $t->string('priority', 12);
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->char('snapshot_hmac', 64);
            $t->unsignedInteger('revision');
            $t->timestampTz('sla_due_at', 6);
            $t->timestampTz('closed_at', 6)->nullable();
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('updated_by');
            $t->timestampsTz(6);

            $t->index(
                ['case_status', 'sla_due_at'],
                'cod_case_status_due'
            );

            $t->index(
                ['assigned_to', 'case_status', 'id'],
                'cod_case_agent_queue'
            );
        });

        Schema::create('checkout_cod_operations_case_events', function (Blueprint $t): void {
            $t->id();

            $t->unsignedBigInteger('case_id');

            $t->foreign('case_id', 'cod_case_event_case_fk')
                ->references('id')
                ->on('checkout_cod_operations_cases')
                ->restrictOnDelete();

            $t->uuid('operation_key')
                ->unique('cod_case_event_operation_once');

            $t->unsignedInteger('revision');
            $t->string('event_action', 32);
            $t->string('reason_code', 40);
            $t->string('from_status', 32)->nullable();
            $t->string('to_status', 32);
            $t->unsignedBigInteger('assigned_from')->nullable();
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->char('snapshot_hmac', 64);
            $t->unsignedBigInteger('actor_id');
            $t->char('request_hmac', 64);

            $t->timestampTz('created_at', 6)->useCurrent();

            $t->unique(
                ['case_id', 'revision'],
                'cod_case_event_revision_once'
            );

            $t->index(
                ['case_id', 'id'],
                'cod_case_event_timeline'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_operations_cases
            ADD CONSTRAINT cod_case_values CHECK (
                case_status IN (
                    'open',
                    'in_progress',
                    'waiting_external',
                    'closed_internal_review'
                )
                AND priority IN ('critical', 'normal', 'low')
                AND revision > 0
                AND created_by > 0
                AND updated_by > 0
                AND snapshot_hmac ~ '^[a-f0-9]{64}$'
                AND (assigned_to IS NULL OR assigned_to > 0)
                AND (
                    (case_status = 'open' AND assigned_to IS NULL)
                    OR (
                        case_status <> 'open'
                        AND assigned_to IS NOT NULL
                    )
                )
                AND (
                    (
                        case_status = 'closed_internal_review'
                        AND closed_at IS NOT NULL
                    )
                    OR (
                        case_status <> 'closed_internal_review'
                        AND closed_at IS NULL
                    )
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_operations_case_events
            ADD CONSTRAINT cod_case_event_values CHECK (
                revision > 0
                AND actor_id > 0
                AND event_action IN (
                    'open',
                    'claim',
                    'request_evidence',
                    'resume',
                    'release',
                    'close',
                    'refresh'
                )
                AND to_status IN (
                    'open',
                    'in_progress',
                    'waiting_external',
                    'closed_internal_review'
                )
                AND (
                    from_status IS NULL
                    OR from_status IN (
                        'open',
                        'in_progress',
                        'waiting_external',
                        'closed_internal_review'
                    )
                )
                AND (assigned_from IS NULL OR assigned_from > 0)
                AND (assigned_to IS NULL OR assigned_to > 0)
                AND snapshot_hmac ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
                AND length(reason_code) BETWEEN 1 AND 40
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION
            boardriders_cod_case_update_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.id <> OLD.id
                    OR NEW.case_key <> OLD.case_key
                    OR NEW.bank_posting_id <> OLD.bank_posting_id
                    OR NEW.created_by <> OLD.created_by
                    OR NEW.created_at <> OLD.created_at
                    OR NEW.revision <> OLD.revision + 1
                THEN
                    RAISE EXCEPTION
                        'COD case update violates immutable identity or revision';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_case_update_guard
            BEFORE UPDATE
            ON checkout_cod_operations_cases
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_case_update_guard()
        SQL);

        // Each case revision must have a corresponding audit event.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION
            boardriders_cod_case_event_required()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM checkout_cod_operations_case_events AS e
                    WHERE e.case_id = NEW.id
                      AND e.revision = NEW.revision
                      AND e.to_status = NEW.case_status
                      AND e.assigned_to IS NOT DISTINCT FROM NEW.assigned_to
                      AND e.snapshot_hmac = NEW.snapshot_hmac
                )
                THEN
                    RAISE EXCEPTION
                        'COD case revision requires an audit event';
                END IF;

                RETURN NULL;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER cod_case_event_required
            AFTER INSERT OR UPDATE
            ON checkout_cod_operations_cases
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_case_event_required()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION
            boardriders_cod_case_no_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION
                    'COD case audit events are immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_case_event_immutable
            BEFORE UPDATE OR DELETE
            ON checkout_cod_operations_case_events
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_case_no_mutation()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_case_event_no_truncate
            BEFORE TRUNCATE
            ON checkout_cod_operations_case_events
            FOR EACH STATEMENT
            EXECUTE FUNCTION boardriders_cod_case_no_mutation()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_case_no_delete
            BEFORE DELETE
            ON checkout_cod_operations_cases
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_case_no_mutation()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_case_no_truncate
            BEFORE TRUNCATE
            ON checkout_cod_operations_cases
            FOR EACH STATEMENT
            EXECUTE FUNCTION boardriders_cod_case_no_mutation()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'checkout_cod_operations_case_events'
        );

        Schema::dropIfExists(
            'checkout_cod_operations_cases'
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_case_update_guard()'
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_case_event_required()'
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_case_no_mutation()'
        );
    }
};
