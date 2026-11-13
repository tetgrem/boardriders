<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutCodCaseAlertInboxService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class CheckoutCodCaseAlertController extends Controller
{
    public function index(
        Request $request,
        CheckoutCodCaseAlertInboxService $service
    ): JsonResponse {
        $v = $request->validate([
            'scope' => [
                'sometimes',
                'in:mine,team',
            ],
            'before_id' => [
                'sometimes',
                'integer',
                'min:1',
            ],
            'limit' => [
                'sometimes',
                'integer',
                'between:1,100',
            ],
        ]);

        return $this->respond(
            fn (): array => $service->listing(
                $v['scope'] ?? 'mine',
                isset($v['before_id'])
                    ? (int) $v['before_id']
                    : null,
                isset($v['limit'])
                    ? (int) $v['limit']
                    : 20
            )
        );
    }

    public function acknowledge(
        int $notificationId,
        CheckoutCodCaseAlertInboxService $service
    ): JsonResponse {
        return $this->respond(
            fn (): array => $service->acknowledge(
                $notificationId
            )
        );
    }

    private function respond(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        }
    }
}
