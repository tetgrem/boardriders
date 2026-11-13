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
            'checkout_cod_operations_reviews',
            function (Blueprint $t): void {
                $t->id();

                $t->uuid('operation_key')
                    ->unique('cod_ops_review_operation_once');

                $t->unsignedBigInteger('bank_posting_id');

                $t->foreign(
                    'bank_posting_id',
                    'cod_ops_review_posting_fk'
                )
                    ->references('id')
                    ->on('checkout_cod_bank_receipt_postings')
                    ->restrictOnDelete();

                $t->string('review_action', 36);
                $t->string('reason_code', 40);
                $t->char('snapshot_hmac', 64);
                $t->unsignedBigInteger('reviewed_by');
                $t->char('request_hmac', 64);

                $t->timestampTz('created_at', 6)
                    ->useCurrent();

                $t->index(
                    ['bank_posting_id', 'id'],
                    'cod_ops_review_history'
                );
            }
        );

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_operations_reviews
            ADD CONSTRAINT cod_ops_review_values CHECK (
                review_action IN (
                    'request_evidence',
                    'escalate_investigation',
                    'acknowledge_comparison'
                )
                AND (
                    (
                        review_action = 'request_evidence'
                        AND reason_code IN (
                            'source_proof_needed',
                            'shipment_proof_needed',
                            'manual_recheck'
                        )
                    )
                    OR (
                        review_action = 'escalate_investigation'
                        AND reason_code IN (
                            'bank_difference',
                            'financial_hold',
                            'reversal',
                            'suspicious_claim',
                            'manual_recheck'
                        )
                    )
                    OR (
                        review_action = 'acknowledge_comparison'
                        AND reason_code = 'allocation_checked'
                    )
                )
                AND snapshot_hmac ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
                AND reviewed_by > 0
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION
            boardriders_cod_ops_review_insert_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                v_posted_by BIGINT;
                v_captured_by BIGINT;
            BEGIN
                SELECT
                    p.posted_by,
                    c.captured_by
                INTO
                    v_posted_by,
                    v_captured_by
                FROM checkout_cod_bank_receipt_postings p
                LEFT JOIN checkout_cod_novapay_register_captures c
                    ON c.bank_posting_id = p.id
                WHERE p.id = NEW.bank_posting_id
                FOR UPDATE OF p;

                IF NOT FOUND
                    OR NEW.reviewed_by = v_posted_by
                    OR (
                        v_captured_by IS NOT NULL
                        AND NEW.reviewed_by = v_captured_by
                    )
                THEN
                    RAISE EXCEPTION
                        'COD review requires an independent operator';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_ops_review_guard
            BEFORE INSERT
            ON checkout_cod_operations_reviews
            FOR EACH ROW
            EXECUTE FUNCTION
                boardriders_cod_ops_review_insert_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION
            boardriders_cod_ops_review_immutable()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'COD operations review history is append-only';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_ops_review_immutable
            BEFORE UPDATE OR DELETE
            ON checkout_cod_operations_reviews
            FOR EACH ROW
            EXECUTE FUNCTION
                boardriders_cod_ops_review_immutable()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_ops_review_no_truncate
            BEFORE TRUNCATE
            ON checkout_cod_operations_reviews
            FOR EACH STATEMENT
            EXECUTE FUNCTION
                boardriders_cod_ops_review_immutable()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'checkout_cod_operations_reviews'
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS ' .
            'boardriders_cod_ops_review_insert_guard()'
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS ' .
            'boardriders_cod_ops_review_immutable()'
        );
    }
};
