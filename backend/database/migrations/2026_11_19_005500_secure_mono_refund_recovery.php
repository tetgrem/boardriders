<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Старые HMAC наблюдений останутся без изменений.
        Schema::table(
            'checkout_mono_refund_observations',
            function (Blueprint $table): void {
                $table->unsignedBigInteger('reviewed_by')->nullable();
            }
        );

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_mono_refund_observations
              ADD CONSTRAINT mono55_reviewed_by_positive
              CHECK (reviewed_by IS NULL OR reviewed_by > 0)
        SQL);

        // Не устанавливаем защиту поверх уже противоречивых данных.
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM checkout_mono_refund_executions e
                    JOIN checkout_payment_capture_receipts r
                      ON r.id = e.capture_receipt_id
                    GROUP BY e.capture_receipt_id, r.amount_minor
                    HAVING SUM(e.amount_minor) > r.amount_minor
                ) THEN
                    RAISE EXCEPTION
                        'Existing Mono refund reservations exceed captured amount';
                END IF;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_mono55_refund_budget_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                captured BIGINT;
                reserved BIGINT;
            BEGIN
                -- Блокируем исходный capture: два процесса не могут
                -- одновременно зарезервировать один и тот же остаток.
                SELECT r.amount_minor INTO captured
                FROM checkout_payment_capture_receipts r
                WHERE r.id = NEW.capture_receipt_id
                FOR UPDATE;

                IF NOT FOUND OR captured <= 0 THEN
                    RAISE EXCEPTION
                        'Original capture not found for Mono refund';
                END IF;

                SELECT COALESCE(SUM(e.amount_minor), 0)
                  INTO reserved
                FROM checkout_mono_refund_executions e
                WHERE e.capture_receipt_id = NEW.capture_receipt_id;

                -- Учитываем даже неопределённые и проблемные операции.
                -- Автоматически их резерв никогда не освобождаем.
                IF reserved > captured
                   OR NEW.amount_minor > captured - reserved
                THEN
                    RAISE EXCEPTION
                        'Mono refund amount exceeds unreserved capture';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER mono55_refund_budget_guard
            BEFORE INSERT ON checkout_mono_refund_executions
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_mono55_refund_budget_guard()
        SQL);
    }

    public function down(): void
    {
        DB::statement(
            'DROP TRIGGER IF EXISTS mono55_refund_budget_guard
             ON checkout_mono_refund_executions'
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_mono55_refund_budget_guard()'
        );

        DB::statement(
            'ALTER TABLE checkout_mono_refund_observations
             DROP CONSTRAINT IF EXISTS mono55_reviewed_by_positive'
        );

        Schema::table(
            'checkout_mono_refund_observations',
            function (Blueprint $table): void {
                $table->dropColumn('reviewed_by');
            }
        );
    }
};
