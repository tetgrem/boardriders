<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ринок — окрема сутність, не просто валюта.
        Schema::create('markets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name', 120);
            $table->char('country_code', 2);
            $table->char('currency_code', 3);
            $table->boolean('is_active')->default(false);
            $table->timestampsTz();

            $table->foreign('currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();

            $table->index(
                ['country_code', 'is_active'],
                'markets_country_active_idx'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE markets
            ADD CONSTRAINT markets_code_country_check
            CHECK (
                code ~ '^[A-Z][A-Z0-9_]{1,15}$'
                AND country_code ~ '^[A-Z]{2}$'
            )
        SQL);

        // Ідентичність покупця, незалежна від ринку.
        Schema::create('loyalty_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            // HMAC-SHA256 від перевіреного guest subject.
            // Сирі токени та контактні дані тут не зберігаємо.
            $table->string('guest_subject_hash', 64)->nullable();
            $table->string('identity_state', 16);
            $table->timestampsTz();

            $table->unique(
                'user_id',
                'loyalty_accounts_user_unique'
            );

            $table->unique(
                'guest_subject_hash',
                'loyalty_accounts_guest_unique'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE loyalty_accounts
            ADD CONSTRAINT loyalty_accounts_identity_check
            CHECK (
                (
                    identity_state = 'guest'
                    AND user_id IS NULL
                    AND guest_subject_hash IS NOT NULL
                )
                OR
                (
                    identity_state = 'verified'
                    AND user_id IS NOT NULL
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE loyalty_accounts
            ADD CONSTRAINT loyalty_accounts_guest_hash_check
            CHECK (
                guest_subject_hash IS NULL
                OR guest_subject_hash ~ '^[0-9a-f]{64}$'
            )
        SQL);

        // Один кошелек для пари (покупець, ринок).
        Schema::create('loyalty_wallets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('loyalty_account_id')
                ->constrained('loyalty_accounts')
                ->restrictOnDelete();

            $table->foreignId('market_id')
                ->constrained('markets')
                ->restrictOnDelete();

            $table->string('status', 16)->default('active');
            $table->timestampsTz();

            $table->unique(
                ['loyalty_account_id', 'market_id'],
                'loyalty_wallets_account_market_unique'
            );

            // Потрібно для складених FK майбутнього ledger.
            $table->unique(
                ['id', 'market_id'],
                'loyalty_wallets_id_market_unique'
            );

            $table->index(
                ['market_id', 'status'],
                'loyalty_wallets_market_status_idx'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE loyalty_wallets
            ADD CONSTRAINT loyalty_wallets_status_check
            CHECK (status IN ('active', 'frozen', 'closed'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_wallets');
        Schema::dropIfExists('loyalty_accounts');
        Schema::dropIfExists('markets');
    }
};
