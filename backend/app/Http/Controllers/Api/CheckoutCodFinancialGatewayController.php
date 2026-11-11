<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutCodFinancialGatewayService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class CheckoutCodFinancialGatewayController extends Controller
{
    public function __invoke(
        Request $request,
        CheckoutCodFinancialGatewayService $service
    ): JsonResponse {
        try {
            $result = $service->accept(
                $request->getContent(),
                (string) $request->header(
                    'X-COD-Gateway-Key',
                    ''
                ),
                (string) $request->header(
                    'X-COD-Gateway-Timestamp',
                    ''
                ),
                (string) $request->header(
                    'X-COD-Gateway-Nonce',
                    ''
                ),
                (string) $request->header(
                    'X-COD-Gateway-Signature',
                    ''
                )
            );

            return response()->json($result, 202);
        } catch (AuthorizationException $e) {
            return response()->json([
                'message' => 'Unauthorized gateway message',
            ], 401);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => 'Invalid gateway payload',
            ], 422);
        } catch (DomainException $e) {
            return response()->json([
                'message' => 'Conflicting gateway delivery',
            ], 409);
        }
    }
}
