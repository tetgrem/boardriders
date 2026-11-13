<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutCodDailyCorrectionService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class CheckoutCodDailyCorrectionController extends Controller
{
    public function index(Request $request, string $reportKey, CheckoutCodDailyCorrectionService $service): JsonResponse
    {
        $v = $request->validate([
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        return $this->json(fn (): array => $service->listing(
            $reportKey, isset($v['before_id']) ? (int) $v['before_id'] : null,
            isset($v['limit']) ? (int) $v['limit'] : 20
        ));
    }

    public function propose(Request $request, string $reportKey, CheckoutCodDailyCorrectionService $service): JsonResponse
    {
        $v = $request->validate([
            'operation_key' => ['required', 'uuid'],
            'reason_code' => ['required', 'in:late_bank_booking,late_register,late_financial_evidence,financial_projection_changed'],
        ]);
        return $this->json(fn (): array => $service->propose(
            $reportKey, $v['operation_key'], $v['reason_code']
        ));
    }

    public function show(string $correctionKey, CheckoutCodDailyCorrectionService $service): JsonResponse
    {
        return $this->json(fn (): array => $service->show($correctionKey));
    }

    public function verify(Request $request, string $correctionKey, CheckoutCodDailyCorrectionService $service): JsonResponse
    {
        $v = $request->validate(['compare_live' => ['sometimes', 'boolean']]);
        return $this->json(fn (): array => $service->verify(
            $correctionKey, (bool) ($v['compare_live'] ?? false)
        ));
    }

    public function act(Request $request, string $correctionKey, CheckoutCodDailyCorrectionService $service): JsonResponse
    {
        $v = $request->validate([
            'operation_key' => ['required', 'uuid'],
            'action' => ['required', 'in:approve,reject,publish'],
            'reason_code' => ['required', 'in:evidence_reviewed,insufficient_evidence,approved_appendix'],
        ]);
        return $this->json(fn (): array => $service->act(
            $correctionKey, $v['operation_key'], $v['action'], $v['reason_code']
        ));
    }

    public function events(Request $request, string $correctionKey, CheckoutCodDailyCorrectionService $service): JsonResponse
    {
        $v = $request->validate([
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        return $this->json(fn (): array => $service->history(
            $correctionKey, isset($v['before_id']) ? (int) $v['before_id'] : null,
            isset($v['limit']) ? (int) $v['limit'] : 20
        ));
    }

    public function csv(Request $request, string $correctionKey, CheckoutCodDailyCorrectionService $service): Response
    {
        $v = $request->validate(['operation_key' => ['required', 'uuid']]);
        try {
            $r = $service->exportCsv($correctionKey, $v['operation_key']);
            return response($r['csv'], 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="cod-correction-' . $r['correction_key'] . '.csv"',
                'Cache-Control' => 'private, no-store',
                'X-COD-Export-Replayed' => $r['idempotent_replay'] ? '1' : '0',
            ]);
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    private function json(callable $fn): JsonResponse
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
