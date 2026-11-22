<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Crm68\Crm68StaffDirectory;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class Crm68StaffController extends Controller
{
    public function index(Request $request, Crm68StaffDirectory $directory): JsonResponse
    {
        $data = $request->validate(['before_id' => ['sometimes', 'integer', 'min:1']]);
        return $this->respond(fn (): array => $directory->page(
            isset($data['before_id']) ? (int) $data['before_id'] : null
        ));
    }

    public function grant(Request $request, Crm68StaffDirectory $directory): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'role' => ['required', 'in:owner,manager,support,finance,fiscal,viewer'],
            'operation_key' => ['required', 'uuid'],
        ]);
        return $this->respond(fn (): array => $directory->grant(
            $data['email'], $data['role'], $data['operation_key']
        ), 201);
    }

    public function change(Request $request, int $userId, Crm68StaffDirectory $directory): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:role_changed,deactivated,reactivated'],
            'role' => ['nullable', 'in:owner,manager,support,finance,fiscal,viewer'],
            'expected_version' => ['required', 'integer', 'min:1', 'max:2147483646'],
            'operation_key' => ['required', 'uuid'],
        ]);
        return $this->respond(fn (): array => $directory->change(
            $userId, $data['action'], $data['role'] ?? null,
            (int) $data['expected_version'], $data['operation_key']
        ));
    }

    public function history(int $userId, Crm68StaffDirectory $directory): JsonResponse
    {
        return $this->respond(fn (): array => $directory->history($userId));
    }

    private function respond(callable $fn, int $status = 200): JsonResponse
    {
        try {
            return response()->json($fn(), $status)->header('Cache-Control', 'no-store');
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409)
                ->header('Cache-Control', 'no-store');
        } catch (RuntimeException $exception) {
            report($exception);
            return response()->json(['message' => 'CRM staff audit integrity check failed'], 503)
                ->header('Cache-Control', 'no-store');
        }
    }
}
