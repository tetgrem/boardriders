<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Crm65\Crm65Staff;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class Crm65StaffCommand extends Command
{
    protected $signature = 'crm65:staff {email} {role=viewer} {--create} {--revoke}';
    protected $description = 'Provision or revoke CRM staff (server console only)';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $role = (string) $this->argument('role');
        $revoke = (bool) $this->option('revoke');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Valid staff email required');
            return self::FAILURE;
        }

        try {
            Crm65Staff::permissionsForRole($role);
        } catch (\DomainException) {
            $this->error('Unknown role');
            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null && !$revoke && $this->option('create')) {
            $password = (string) $this->secret('New staff password (at least 16 characters)');
            if (mb_strlen($password) < 16) {
                $this->error('Password too short');
                return self::FAILURE;
            }
            $name = trim((string) $this->ask('Staff display name'));
            if ($name === '') {
                $this->error('Name required');
                return self::FAILURE;
            }
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
            ]);
            $user->email_verified_at = now();
            $user->save();
        }

        if ($user === null || $user->email_verified_at === null) {
            $this->error('Existing verified user required (or --create)');
            return self::FAILURE;
        }

        if (!$this->confirm($revoke ? 'Revoke CRM access?' : "Grant CRM role {$role}?", false)) {
            $this->warn('Cancelled');
            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $role, $revoke): void {
            DB::table('checkout_crm_staff65')->updateOrInsert(
                ['user_id' => (int) $user->id],
                [
                    'role' => $role,
                    'active' => !$revoke,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            // Role changes take effect immediately; revoke existing sessions.
            $user->tokens()->where('name', 'crm65-dashboard')->delete();
        });

        $this->info($revoke ? 'CRM access revoked' : 'CRM staff role updated');
        return self::SUCCESS;
    }
}
