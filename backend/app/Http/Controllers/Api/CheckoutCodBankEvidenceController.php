<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutCodBankEvidenceService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Machine-to-machine endpoint, disabled until a verified source key is provisioned. */
final class CheckoutCodBankEvidenceController extends Controller
{
    public function __invoke(Request $request, CheckoutCodBankEvidenceService $service): JsonResponse
    {
        try {
            $result = $service->receive(
                $request->getContent(),
                (string) $request->header('X-COD-Bank-Provider', ''),
                (string) $request->header('X-COD-Bank-Key', ''),
                (string) $request->header('X-COD-Bank-Timestamp', ''),
                (string) $request->header('X-COD-Bank-Nonce', ''),
                (string) $request->header('X-COD-Bank-Signature', '')
            );
            return response()->json($result, 202);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => 'Unauthorized signed source'], 401);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => 'Invalid signed source payload'], 422);
        } catch (DomainException $e) {
            return response()->json(['message' => 'Conflicting signed source event'], 409);
        }
    }
}
