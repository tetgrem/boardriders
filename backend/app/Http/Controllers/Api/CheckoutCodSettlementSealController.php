<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutCodSettlementSealService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class CheckoutCodSettlementSealController extends Controller
{
    public function preview(Request $request, CheckoutCodSettlementSealService $service): JsonResponse
    {
        $v = $request->validate(['posting_key' => ['required', 'uuid']]);
        return $this->respond(fn (): array => $service->preview($v['posting_key']));
    }

    public function seal(Request $request, CheckoutCodSettlementSealService $service): JsonResponse
    {
        $v = $request->validate([
            'posting_key' => ['required', 'uuid'],
            'operation_key' => ['required', 'uuid'],
        ]);
        return $this->respond(
            fn (): array => $service->seal($v['posting_key'], $v['operation_key'])
        );
    }

    public function show(string $sealKey, CheckoutCodSettlementSealService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->show($sealKey));
    }

    private function respond(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }
}
