<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Crm64\Crm64Card;
use App\Services\Crm64\Crm64Dashboard;
use App\Services\Crm64\Crm64Notes;
use App\Services\Crm64\Crm64Orders;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class Crm64Controller extends Controller
{
    public function index(Request $request, Crm64Orders $service): JsonResponse
    {
        $filters = $request->validate([
            'limit' => ['sometimes','integer','min:1','max:100'],
            'before_id' => ['sometimes','integer','min:1'],
            'order_key' => ['sometimes','string','max:36'],
            'payment_status' => ['sometimes','string','max:32'],
            'has_rma' => ['sometimes','boolean'],
            'has_fiscal_issue' => ['sometimes','boolean'],
        ]);
        foreach (['has_rma','has_fiscal_issue'] as $key) {
            if (array_key_exists($key, $filters)) {
                $filters[$key] = filter_var($filters[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }
        }
        return $this->respond(fn (): array => $service->page($filters));
    }

    public function show(string $orderKey, Crm64Card $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->show($orderKey));
    }

    public function finance(string $orderKey, Crm64Card $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->finance($orderKey));
    }

    public function timeline(Request $request, string $orderKey, Crm64Card $service): JsonResponse
    {
        $data = $request->validate(['limit' => ['sometimes','integer','min:1','max:100']]);
        return $this->respond(fn (): array => $service->timeline($orderKey, (int) ($data['limit'] ?? 50)));
    }

    public function accessHistory(Request $request, string $orderKey, Crm64Card $service): JsonResponse
    {
        $data = $request->validate(['limit' => ['sometimes','integer','min:1','max:100']]);
        return $this->respond(fn (): array => $service->accessHistory($orderKey, (int) ($data['limit'] ?? 30)));
    }

    public function note(Request $request, string $orderKey, Crm64Notes $service): JsonResponse
    {
        $data = $request->validate([
            'operation_key' => ['required','uuid'],
            'category' => ['required','in:service,finance,delivery,return,fiscal'],
            'body' => ['required','string','min:4','max:1000'],
        ]);
        return $this->respond(fn (): array => $service->create(
            $orderKey, $data['operation_key'], $data['category'], $data['body']
        ), 201);
    }

    public function dashboard(Crm64Dashboard $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->summary());
    }

    private function respond(callable $action, int $ok = 200): JsonResponse
    {
        try {
            return response()->json($action(), $ok);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (RuntimeException $exception) {
            report($exception);
            return response()->json(['message' => 'CRM64 source integrity check failed'], 503);
        }
    }
}
