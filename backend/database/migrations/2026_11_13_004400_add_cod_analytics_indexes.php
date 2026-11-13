<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('checkout_cod_bank_receipt_postings', function (Blueprint $t): void {
            $t->index(['created_at', 'id'], 'cod_analytics_posting_created');
        });
        Schema::table('checkout_cod_operations_cases', function (Blueprint $t): void {
            $t->index(['created_at', 'id'], 'cod_analytics_case_created');
        });
        Schema::table('checkout_cod_case_alert_outbox', function (Blueprint $t): void {
            $t->index(['created_at', 'id'], 'cod_analytics_alert_created');
        });
        Schema::table('checkout_cod_case_alert_inbox', function (Blueprint $t): void {
            $t->index(['created_at', 'id'], 'cod_analytics_inbox_created');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_cod_case_alert_inbox', function (Blueprint $t): void {
            $t->dropIndex('cod_analytics_inbox_created');
        });
        Schema::table('checkout_cod_case_alert_outbox', function (Blueprint $t): void {
            $t->dropIndex('cod_analytics_alert_created');
        });
        Schema::table('checkout_cod_operations_cases', function (Blueprint $t): void {
            $t->dropIndex('cod_analytics_case_created');
        });
        Schema::table('checkout_cod_bank_receipt_postings', function (Blueprint $t): void {
            $t->dropIndex('cod_analytics_posting_created');
        });
    }
};
