<?php

declare(strict_types=1);

namespace App\Services\Crm68;

use App\Models\User;
use App\Services\Crm65\Crm65Staff;
use App\Services\Pricing\CheckoutOrderReturnSigner;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;

/** Staff grants only. Account signup and password delivery are deliberately separate. */
final class Crm68StaffDirectory
{
    private const ROLES = ['owner', 'manager', 'support', 'finance', 'fiscal', 'viewer'];

    public function __construct(private readonly CheckoutOrderReturnSigner $signer) {}

    public function page(?int $beforeId = null): array
    {
        $this->actor();
        if ($beforeId !== null && $beforeId < 1) {
            throw new DomainException('Invalid staff cursor');
        }

        $query = DB::table('checkout_crm_staff65 as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->select('s.user_id', 's.role', 's.active', 's.version', 's.updated_at',
                'u.name', 'u.email')
            ->when($beforeId !== null, fn ($q) => $q->where('s.user_id', '<', $beforeId))
            ->orderByDesc('s.user_id')
            ->limit(51);

        $rows = $query->get();
        $more = $rows->count() > 50;
        $items = $rows->take(50)->map(fn (object $row): array => $this->verifiedStaff($row))->all();

        return [
            'items' => array_values($items),
            'next_before_id' => $more && $items !== [] ? $items[count($items) - 1]['user_id'] : null,
        ];
    }

    public function grant(string $email, string $role, string $operationKey): array
    {
        $actor = $this->actor();
        $email = mb_strtolower(trim($email));
        $key = $this->uuid($operationKey);
        $this->validRole($role);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            throw new DomainException('Invalid staff email');
        }

        return DB::transaction(function () use ($actor, $email, $role, $key): array {
            $user = DB::table('users')->where('email', $email)->lockForUpdate()
                ->first(['id', 'email_verified_at']);
            if ($user === null || $user->email_verified_at === null) {
                throw new DomainException('A verified user account is required');
            }
            $target = (int) $user->id;
            $existing = DB::table('checkout_crm_staff_events68')->where('operation_key', $key)->first();
            if ($existing !== null) {
                $event = $this->verifiedEvent($existing);
                if ($event['actor_id'] !== $actor || $event['target_user_id'] !== $target
                    || $event['action'] !== 'granted' || $event['to_role'] !== $role) {
                    throw new DomainException('CRM staff operation key reused');
                }
                return ['member' => $this->member($target), 'replayed' => true];
            }
            if (DB::table('checkout_crm_staff65')->where('user_id', $target)->exists()) {
                throw new DomainException('User already has a CRM staff record');
            }
            DB::table('checkout_crm_staff65')->insert([
                'user_id' => $target, 'role' => $role, 'active' => true,
                'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->append([
                'operation_key' => $key,
                'target_user_id' => $target,
                'actor_id' => $actor,
                'action' => 'granted',
                'from_role' => null,
                'to_role' => $role,
                'from_active' => null,
                'to_active' => true,
                'previous_version' => 0,
                'version' => 1,
            ]);
            return ['member' => $this->member($target), 'replayed' => false];
        }, 3);
    }

    public function change(int $target, string $action, ?string $role, int $expectedVersion, string $operationKey): array
    {
        $actor = $this->actor();
        $key = $this->uuid($operationKey);
        if ($target < 1 || $expectedVersion < 1 || $expectedVersion >= 2147483647
            || !in_array($action, ['role_changed', 'deactivated', 'reactivated'], true)) {
            throw new DomainException('Invalid CRM staff operation');
        }
        if ($action === 'role_changed') {
            $this->validRole($role);
        } elseif ($role !== null) {
            throw new DomainException('Role must be absent for access changes');
        }

        return DB::transaction(function () use ($target, $action, $role, $expectedVersion, $key, $actor): array {
            // Lock owners in a consistent order, before locking the target.
            // This avoids concurrent changes accidentally leaving zero active owners.
            $owners = DB::table('checkout_crm_staff65')
                ->where('role', 'owner')->where('active', true)
                ->orderBy('user_id')->lockForUpdate()->pluck('user_id')->all();

            $staff = DB::table('checkout_crm_staff65')
                ->where('user_id', $target)->lockForUpdate()->first();
            if ($staff === null) throw new DomainException('CRM employee not found');
            $this->verifyState($staff);

            $existing = DB::table('checkout_crm_staff_events68')->where('operation_key', $key)->first();
            if ($existing !== null) {
                $event = $this->verifiedEvent($existing);
                if ($event['actor_id'] !== $actor || $event['target_user_id'] !== $target
                    || $event['action'] !== $action
                    || $event['previous_version'] !== $expectedVersion
                    || ($action === 'role_changed' && $event['to_role'] !== $role)) {
                    throw new DomainException('CRM staff operation key reused');
                }
                return ['member' => $this->member($target), 'replayed' => true];
            }

            if ($target === $actor) {
                throw new DomainException('You cannot change your own CRM access');
            }
            if ((int) $staff->version !== $expectedVersion) {
                throw new DomainException('CRM employee changed. Refresh the page');
            }
            $newRole = (string) $staff->role;
            $newActive = (bool) $staff->active;
            if ($action === 'role_changed') {
                if ($newRole === $role) throw new DomainException('Role is already assigned');
                $newRole = (string) $role;
            } elseif ($action === 'deactivated') {
                if (!$newActive) throw new DomainException('Employee is already blocked');
                $newActive = false;
            } else {
                if ($newActive) throw new DomainException('Employee is already active');
                $newActive = true;
            }

            if ((string) $staff->role === 'owner' && (bool) $staff->active
                && ($newRole !== 'owner' || !$newActive) && count($owners) < 2) {
                throw new DomainException('Cannot remove the last active CRM owner');
            }

            DB::table('checkout_crm_staff65')->where('user_id', $target)->update([
                'role' => $newRole,
                'active' => $newActive,
                'version' => $expectedVersion + 1,
                'updated_at' => now(),
            ]);

            // A prior bearer token must not retain a higher role or access.
            User::query()->findOrFail($target)->tokens()
                ->where('name', 'crm65-dashboard')->delete();

            $this->append([
                'operation_key' => $key,
                'target_user_id' => $target,
                'actor_id' => $actor,
                'action' => $action,
                'from_role' => (string) $staff->role,
                'to_role' => $newRole,
                'from_active' => (bool) $staff->active,
                'to_active' => $newActive,
                'previous_version' => $expectedVersion,
                'version' => $expectedVersion + 1,
            ]);

            return ['member' => $this->member($target), 'replayed' => false];
        }, 3);
    }

    public function history(int $target): array
    {
        $this->actor();
        if ($target < 1 || !DB::table('checkout_crm_staff65')->where('user_id', $target)->exists()) {
            throw new DomainException('CRM employee not found');
        }
        $events = DB::table('checkout_crm_staff_events68')->where('target_user_id', $target)
            ->orderByDesc('id')->limit(50)->get();
        return $events->map(fn (object $row): array => [
            ...$this->verifiedEvent($row),
            'created_at' => (string) $row->created_at,
        ])->all();
    }

    private function member(int $userId): array
    {
        $row = DB::table('checkout_crm_staff65 as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.user_id', $userId)
            ->first(['s.user_id', 's.role', 's.active', 's.version', 's.updated_at', 'u.name', 'u.email']);
        if ($row === null) throw new RuntimeException('CRM staff record disappeared');
        return $this->verifiedStaff($row);
    }

    private function verifiedStaff(object $row): array
    {
        $this->verifyState($row);
        return [
            'user_id' => (int) $row->user_id,
            'name' => (string) $row->name,
            'email' => (string) $row->email,
            'role' => (string) $row->role,
            'active' => (bool) $row->active,
            'version' => (int) $row->version,
            'updated_at' => (string) $row->updated_at,
        ];
    }

    private function verifyState(object $row): void
    {
        $eventRow = DB::table('checkout_crm_staff_events68')->where('target_user_id', $row->user_id)
            ->orderByDesc('version')->first();
        if ($eventRow !== null) {
            $event = $this->verifiedEvent($eventRow);
            if ($event['version'] !== (int) $row->version
                || $event['to_role'] !== (string) $row->role
                || $event['to_active'] !== (bool) $row->active) {
                throw new RuntimeException('CRM staff projection differs from signed audit');
            }
        } elseif ((int) $row->version !== 1) {
            throw new RuntimeException('CRM staff history is missing');
        }
    }

    private function append(array $event): void
    {
        DB::table('checkout_crm_staff_events68')->insert([
            ...$event,
            'event_hmac' => $this->signer->digest(['domain' => 'boardriders-crm68-staff-v1', ...$event]),
            'created_at' => now(),
        ]);
    }

    private function verifiedEvent(object $row): array
    {
        $event = [
            'operation_key' => (string) $row->operation_key,
            'target_user_id' => (int) $row->target_user_id,
            'actor_id' => (int) $row->actor_id,
            'action' => (string) $row->action,
            'from_role' => $row->from_role === null ? null : (string) $row->from_role,
            'to_role' => (string) $row->to_role,
            'from_active' => $row->from_active === null ? null : (bool) $row->from_active,
            'to_active' => (bool) $row->to_active,
            'previous_version' => (int) $row->previous_version,
            'version' => (int) $row->version,
        ];
        $digest = $this->signer->digest(['domain' => 'boardriders-crm68-staff-v1', ...$event]);
        if (!hash_equals((string) $row->event_hmac, $digest)) {
            throw new RuntimeException('CRM staff audit signature mismatch');
        }
        return $event;
    }

    private function actor(): int
    {
        if (config('crm68.enabled') !== true || config('crm65.enabled') !== true) {
            abort(503, 'CRM staff administration disabled');
        }
        $user = Auth::user();
        if (!$user instanceof User) abort(401);
        Gate::forUser($user)->authorize('crm68.staff.manage');
        return (int) $user->getKey();
    }

    private function validRole(?string $role): void
    {
        if ($role === null || !in_array($role, self::ROLES, true)) {
            throw new DomainException('Invalid CRM role');
        }
        Crm65Staff::permissionsForRole($role);
    }

    private function uuid(string $key): string
    {
        if (!Str::isUuid($key)) throw new DomainException('Invalid CRM operation UUID');
        return strtolower($key);
    }
}
