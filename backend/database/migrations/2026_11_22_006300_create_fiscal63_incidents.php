<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_fiscal_cases63', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('ticket_id')
                ->constrained('checkout_fiscal_tickets62')
                ->restrictOnDelete();
            $t->string('reason_code', 40);
            $t->string('ticket_status_at_open', 24);
            $t->char('request_sha256', 64);
            $t->foreignId('event_id_at_open')
                ->constrained('checkout_fiscal_events62')
                ->restrictOnDelete();
            $t->unsignedBigInteger('opened_by');
            $t->char('case_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();

            $t->unique(
                ['ticket_id', 'reason_code'],
                'fiscal63_unique_ticket_reason'
            );
            $t->index(
                ['created_at', 'id'],
                'fiscal63_case_created_idx'
            );
        });

        Schema::create('checkout_fiscal_case_actions63', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('case_id')
                ->constrained('checkout_fiscal_cases63')
                ->restrictOnDelete();
            $t->string('action', 24);
            $t->unsignedBigInteger('actor_id');
            $t->string('note', 500)->nullable();
            $t->char('action_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->index(
                ['case_id', 'id'],
                'fiscal63_action_case_idx'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_fiscal_cases63
            ADD CONSTRAINT fiscal63_case_shape CHECK (
                reason_code IN (
                    'stale_transmission',
                    'uncertain_submission',
                    'pending_too_long',
                    'provider_needs_review',
                    'prepared_backlog'
                )
                AND opened_by > 0
                AND request_sha256 ~ '^[a-f0-9]{64}$'
                AND case_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_fiscal_case_actions63
            ADD CONSTRAINT fiscal63_action_shape CHECK (
                action IN (
                    'acknowledge',
                    'note',
                    'poll_requested',
                    'recover_uncertain',
                    'close'
                )
                AND actor_id > 0
                AND action_hmac ~ '^[a-f0-9]{64}$'
                AND (
                    (action = 'note' AND length(note) BETWEEN 4 AND 500)
                    OR
                    (action <> 'note' AND note IS NULL)
                )
            )
        SQL);

        DB::statement(
            "CREATE UNIQUE INDEX fiscal63_once_ack
             ON checkout_fiscal_case_actions63(case_id)
             WHERE action = 'acknowledge'"
        );

        DB::statement(
            "CREATE UNIQUE INDEX fiscal63_once_close
             ON checkout_fiscal_case_actions63(case_id)
             WHERE action = 'close'"
        );

        DB::statement(
            "CREATE UNIQUE INDEX fiscal63_once_recover
             ON checkout_fiscal_case_actions63(case_id)
             WHERE action = 'recover_uncertain'"
        );

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_fiscal63_case_insert_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM checkout_fiscal_events62 e
                    WHERE e.id = NEW.event_id_at_open
                      AND e.ticket_id = NEW.ticket_id
                ) THEN
                    RAISE EXCEPTION
                        'Fiscal63 case event belongs to another ticket';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_fiscal63_action_insert_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                opener bigint;
                acknowledger bigint;
                ticket bigint;
            BEGIN
                SELECT opened_by, ticket_id
                INTO opener, ticket
                FROM checkout_fiscal_cases63
                WHERE id = NEW.case_id;

                IF opener IS NULL THEN
                    RAISE EXCEPTION 'Fiscal63 case missing';
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM checkout_fiscal_case_actions63
                    WHERE case_id = NEW.case_id
                      AND action = 'close'
                ) THEN
                    RAISE EXCEPTION
                        'Fiscal63 case is already closed';
                END IF;

                SELECT actor_id INTO acknowledger
                FROM checkout_fiscal_case_actions63
                WHERE case_id = NEW.case_id
                  AND action = 'acknowledge';

                IF NEW.action = 'acknowledge' THEN
                    IF NEW.actor_id = opener
                        OR acknowledger IS NOT NULL
                    THEN
                        RAISE EXCEPTION
                            'Fiscal63 independent first acknowledgement required';
                    END IF;

                ELSIF NEW.action IN (
                    'poll_requested',
                    'recover_uncertain',
                    'close'
                ) THEN
                    IF acknowledger IS NULL
                        OR NEW.actor_id = opener
                    THEN
                        RAISE EXCEPTION
                            'Fiscal63 acknowledgement and independent actor required';
                    END IF;

                    IF NEW.action = 'close' THEN
                        IF NEW.actor_id = acknowledger
                            OR NOT EXISTS (
                                SELECT 1
                                FROM checkout_fiscal_tickets62 t
                                WHERE t.id = ticket
                                  AND t.status = 'done'
                                  AND t.fiscal_code IS NOT NULL
                                  AND EXISTS (
                                      SELECT 1
                                      FROM checkout_fiscal_events62 e
                                      WHERE e.ticket_id = ticket
                                        AND e.kind = 'provider_done'
                                  )
                            )
                        THEN
                            RAISE EXCEPTION
                                'Fiscal63 closure requires independent verified fiscal DONE';
                        END IF;
                    END IF;

                    IF NEW.action = 'recover_uncertain'
                        AND NOT EXISTS (
                            SELECT 1
                            FROM checkout_fiscal_cases63 c
                            JOIN checkout_fiscal_tickets62 t
                              ON t.id = c.ticket_id
                            WHERE c.id = NEW.case_id
                              AND c.reason_code = 'stale_transmission'
                              AND t.status = 'uncertain'
                        )
                    THEN
                        RAISE EXCEPTION
                            'Fiscal63 recovery must preserve uncertain status';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_fiscal63_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Fiscal63 audit is append-only';
            END;
            $$
        SQL);

        DB::statement(
            'CREATE TRIGGER fiscal63_case_insert
             BEFORE INSERT ON checkout_fiscal_cases63
             FOR EACH ROW
             EXECUTE FUNCTION boardriders_fiscal63_case_insert_guard()'
        );

        DB::statement(
            'CREATE TRIGGER fiscal63_action_insert
             BEFORE INSERT ON checkout_fiscal_case_actions63
             FOR EACH ROW
             EXECUTE FUNCTION boardriders_fiscal63_action_insert_guard()'
        );

        foreach ([
                     'checkout_fiscal_cases63',
                     'checkout_fiscal_case_actions63',
                 ] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_immutable
                 BEFORE UPDATE OR DELETE ON {$table}
                 FOR EACH ROW
                 EXECUTE FUNCTION boardriders_fiscal63_immutable()"
            );

            DB::statement(
                "CREATE TRIGGER {$table}_no_truncate
                 BEFORE TRUNCATE ON {$table}
                 FOR EACH STATEMENT
                 EXECUTE FUNCTION boardriders_fiscal63_immutable()"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_fiscal_case_actions63');
        Schema::dropIfExists('checkout_fiscal_cases63');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_fiscal63_case_insert_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_fiscal63_action_insert_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_fiscal63_immutable()'
        );
    }
};
