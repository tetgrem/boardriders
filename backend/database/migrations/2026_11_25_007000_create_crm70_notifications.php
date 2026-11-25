<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_crm_notification_reads70', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('notification_key', 64);
            $table->timestampTz('read_at', 6);

            $table->unique(
                ['user_id', 'notification_key'],
                'crm70_user_notification_unique'
            );

            $table->index(
                ['user_id', 'read_at'],
                'crm70_user_reads_idx'
            );
        });

        Schema::create('checkout_crm_notification_preferences70', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('category', 24);
            $table->boolean('enabled')->default(true);
            $table->timestampsTz(6);

            $table->unique(
                ['user_id', 'category'],
                'crm70_user_category_unique'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_crm_notification_reads70
            ADD CONSTRAINT crm70_notification_key_shape
            CHECK (
                notification_key ~ '^[a-z_]+:[0-9]+(:[0-9]+)?$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_crm_notification_preferences70
            ADD CONSTRAINT crm70_preference_category_check
            CHECK (
                category IN (
                    'orders',
                    'payments',
                    'returns',
                    'delivery',
                    'fiscal',
                    'sla'
                )
            )
        SQL);

        DB::statement(
            'CREATE INDEX crm70_orders_created_idx ON orders (created_at, id)'
        );

        DB::statement(
            "CREATE INDEX crm70_orders_review_idx ON orders (id)
             WHERE payment_status = 'review'"
        );
    }

    public function down(): void
    {
        DB::statement(
            'DROP INDEX IF EXISTS crm70_orders_created_idx'
        );

        DB::statement(
            'DROP INDEX IF EXISTS crm70_orders_review_idx'
        );

        Schema::dropIfExists(
            'checkout_crm_notification_preferences70'
        );

        Schema::dropIfExists(
            'checkout_crm_notification_reads70'
        );
    }
};
