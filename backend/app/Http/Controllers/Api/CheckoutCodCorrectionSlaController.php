<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutCodCorrectionSlaInboxService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class CheckoutCodCorrectionSlaController extends Controller
{
    public function index(Request $request, CheckoutCodCorrectionSlaInboxService $inbox): JsonResponse
    {
        $v = $request->validate([
            'scope' => ['sometimes', 'in:all,unacknowledged,critical'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        return $this->respond(fn (): array => $inbox->listing(
            $v['scope'] ?? 'unacknowledged',
            isset($v['before_id']) ? (int) $v['before_id'] : null,
            isset($v['limit']) ? (int) $v['limit'] : 20
        ));
    }

    public function summary(CheckoutCodCorrectionSlaInboxService $inbox): JsonResponse
    {
        return $this->respond(fn (): array => $inbox->summary());
    }

    public function history(
        Request $request, string $correctionKey,
        CheckoutCodCorrectionSlaInboxService $inbox
    ): JsonResponse {
        $v = $request->validate([
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        return $this->respond(fn (): array => $inbox->history(
            $correctionKey,
            isset($v['before_id']) ? (int) $v['before_id'] : null,
            isset($v['limit']) ? (int) $v['limit'] : 20
        ));
    }

    public function acknowledge(
        Request $request, int $alertId,
        CheckoutCodCorrectionSlaInboxService $inbox
    ): JsonResponse {
        $v = $request->validate(['operation_key' => ['required', 'uuid']]);
        return $this->respond(fn (): array => $inbox->acknowledge(
            $alertId, $v['operation_key']
        ));
    }

    private function respond(callable $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
