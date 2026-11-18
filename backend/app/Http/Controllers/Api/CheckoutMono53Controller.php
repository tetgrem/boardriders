<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutMonoPayment53Service;
use App\Services\Pricing\CheckoutMonoRmaRefund53Service;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

final class CheckoutMono53Controller extends Controller
{
    public function start(
        Request $request,
        CheckoutMonoPayment53Service $service
    ): JsonResponse {
        $v = $request->validate([
            'order_key' => ['required', 'uuid'],
            'operation_key' => ['required', 'uuid'],
        ]);

        return $this->admin(
            fn (): array => $service->start(
                $v['order_key'],
                $v['operation_key']
            ),
            201
        );
    }

    public function reconcile(
        string $invoiceId,
        CheckoutMonoPayment53Service $service
    ): JsonResponse {
        return $this->admin(
            fn (): array => $service->reconcile($invoiceId)
        );
    }

    public function planRefund(
        Request $request,
        string $returnKey,
        CheckoutMonoRmaRefund53Service $service
    ): JsonResponse {
        $v = $request->validate([
            'operation_key' => ['required', 'uuid'],
        ]);

        return $this->admin(
            fn (): array => $service->plan(
                $returnKey,
                $v['operation_key']
            ),
            201
        );
    }

    public function webhook(
        Request $request,
        CheckoutMonoPayment53Service $service
    ): JsonResponse {
        try {
            $out = $service->webhook(
                $request->getContent(),
                $request->header('X-Sign')
            );

            return response()->json($out, 200);
        } catch (AuthorizationException) {
            return response()->json([
                'message' => 'Invalid signature',
            ], 401);
        } catch (InvalidArgumentException) {
            return response()->json([
                'message' => 'Invalid webhook',
            ], 422);
        } catch (DomainException) {
            return response()->json([
                'message' => 'Payment verification not completed',
            ], 409);
        } catch (RuntimeException) {
            return response()->json([
                'message' =>
                    'Provider verification temporarily unavailable',
            ], 503);
        }
    }

    private function admin(
        callable $fn,
        int $success = 200
    ): JsonResponse {
        try {
            return response()->json($fn(), $success);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        } catch (RuntimeException) {
            return response()->json([
                'message' =>
                    'Mono provider request requires reconciliation',
            ], 503);
        }
    }
}
