<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Crm69\Crm69Sla;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class Crm69SlaController extends Controller
{
    public function overview(Request $request, Crm69Sla $service): JsonResponse
    {
        $data = $request->validate([
            'filter' => ['sometimes', 'in:all,mine,overdue,soon,unplanned'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        return $this->run(fn (): array => $service->overview($data));
    }

    public function assignees(Crm69Sla $service): JsonResponse
    {
        return $this->run(fn (): array => $service->assignees());
    }

    public function history(int $taskId, Crm69Sla $service): JsonResponse
    {
        return $this->run(fn (): array => $service->history($taskId));
    }

    public function deadline(Request $request, int $taskId, Crm69Sla $service): JsonResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'uuid'],
            'due_at' => ['present', 'nullable', 'date_format:Y-m-d\\TH:i:s\\Z'],
            'expected_revision' => ['required', 'integer', 'min:0', 'max:2147483646'],
        ]);
        return $this->run(fn (): array => $service->setDeadline(
            $taskId, $data['due_at'], (int) $data['expected_revision'], $data['operation_key']
        ));
    }

    public function comment(Request $request, int $taskId, Crm69Sla $service): JsonResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'uuid'],
            'body' => ['required', 'string', 'min:2', 'max:500'],
        ]);
        return $this->run(fn (): array => $service->comment($taskId, $data['body'], $data['operation_key']), 201);
    }

    public function assign(Request $request, int $taskId, Crm69Sla $service): JsonResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'uuid'],
            'assignee_user_id' => ['present', 'nullable', 'integer', 'min:1'],
            'expected_version' => ['required', 'integer', 'min:1', 'max:2147483646'],
        ]);
        return $this->run(fn (): array => $service->assign(
            $taskId, isset($data['assignee_user_id']) ? (int) $data['assignee_user_id'] : null,
            (int) $data['expected_version'], $data['operation_key']
        ));
    }

    private function run(callable $callback, int $status = 200): JsonResponse
    {
        try {
            return response()->json($callback(), $status)->header('Cache-Control', 'no-store');
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409)
                ->header('Cache-Control', 'no-store');
        } catch (RuntimeException $exception) {
            report($exception);
            return response()->json(['message' => 'CRM69 integrity check failed'], 503)
                ->header('Cache-Control', 'no-store');
        }
    }
}
