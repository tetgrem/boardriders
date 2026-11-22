<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Crm66\Crm66Tasks;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class Crm66TasksController extends Controller
{
    public function index(string $orderKey, Crm66Tasks $tasks): JsonResponse
    {
        return $this->respond(fn (): array => $tasks->list($orderKey));
    }

    public function create(Request $request, string $orderKey, Crm66Tasks $tasks): JsonResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'uuid'],
            'title' => ['required', 'string', 'min:4', 'max:160'],
            'priority' => ['required', 'in:low,normal,high,critical'],
        ]);
        return $this->respond(fn (): array => $tasks->create(
            $orderKey, $data['operation_key'], $data['title'], $data['priority']
        ), 201);
    }

    public function action(Request $request, string $orderKey, int $taskId, Crm66Tasks $tasks): JsonResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'uuid'],
            'action' => ['required', 'in:claim,release,start,resolve,reopen'],
            'expected_version' => ['required', 'integer', 'min:1', 'max:2147483646'],
        ]);
        return $this->respond(fn (): array => $tasks->act(
            $orderKey, $taskId, $data['operation_key'], $data['action'], (int) $data['expected_version']
        ));
    }

    public function history(string $orderKey, int $taskId, Crm66Tasks $tasks): JsonResponse
    {
        return $this->respond(fn (): array => $tasks->history($orderKey, $taskId));
    }

    private function respond(callable $action, int $status = 200): JsonResponse
    {
        try {
            return response()->json($action(), $status)->header('Cache-Control', 'no-store');
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (RuntimeException $exception) {
            report($exception);
            return response()->json(['message' => 'CRM task integrity check failed'], 503);
        }
    }
}
