<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Зберігаємо мікросекунди для всіх фінансових подій.
        foreach ([
                     'loyalty_ledger_entries' => [
                         'occurred_at', 'posted_at',
                     ],
                     'loyalty_grants' => [
                         'granted_at', 'activation_not_before_at',
                         'expires_at', 'created_at',
                     ],
                     'loyalty_grant_expiry_adjustments' => [
                         'old_expires_at', 'new_expires_at',
                         'effective_at', 'created_at',
                     ],
                     'loyalty_operations' => ['created_at'],
                     'loyalty_reservations' => [
                         'expires_at', 'created_at',
                     ],
                     'loyalty_reservation_lines' => ['created_at'],
                     'loyalty_grant_reversals' => ['created_at'],
                     'loyalty_redemption_refunds' => ['created_at'],
                 ] as $table => $columns) {
            $alterations = array_map(
                static fn (string $column): string =>
                "ALTER COLUMN {$column} TYPE TIMESTAMPTZ(6)",
                $columns
            );

            DB::statement(
                "ALTER TABLE {$table} " . implode(', ', $alterations)
            );
        }
    }

    public function down(): void
    {
        // Не знижуємо точність фінансової історії:
        // це призвело б до втрати мікросекунд.
        throw new RuntimeException(
            'Cannot safely downgrade loyalty timestamp precision.'
        );
    }
};
