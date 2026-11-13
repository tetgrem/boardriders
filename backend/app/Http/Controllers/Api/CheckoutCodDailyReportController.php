<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\CheckoutCodDailyReportService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class CheckoutCodDailyReportController extends Controller
{
    public function index(
        Request $request,
        CheckoutCodDailyReportService $reports
    ): JsonResponse {
        $v = $request->validate([
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        return $this->json(fn (): array => $reports->listing(
            isset($v['before_id']) ? (int) $v['before_id'] : null,
            isset($v['limit']) ? (int) $v['limit'] : 20
        ));
    }

    public function seal(
        Request $request,
        CheckoutCodDailyReportService $reports
    ): JsonResponse {
        $v = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'operation_key' => ['required', 'uuid'],
        ]);

        return $this->json(
            fn (): array => $reports->seal($v['date'], $v['operation_key'])
        );
    }

    public function show(
        string $reportKey,
        CheckoutCodDailyReportService $reports
    ): JsonResponse {
        return $this->json(fn (): array => $reports->show($reportKey));
    }

    public function verify(
        Request $request,
        string $reportKey,
        CheckoutCodDailyReportService $reports
    ): JsonResponse {
        $v = $request->validate([
            'compare_live' => ['sometimes', 'boolean'],
        ]);

        return $this->json(fn (): array => $reports->verify(
            $reportKey,
            (bool) ($v['compare_live'] ?? false)
        ));
    }

    public function events(
        Request $request,
        string $reportKey,
        CheckoutCodDailyReportService $reports
    ): JsonResponse {
        $v = $request->validate([
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        return $this->json(fn (): array => $reports->history(
            $reportKey,
            isset($v['before_id']) ? (int) $v['before_id'] : null,
            isset($v['limit']) ? (int) $v['limit'] : 20
        ));
    }

    public function csv(
        Request $request,
        string $reportKey,
        CheckoutCodDailyReportService $reports
    ): Response {
        $v = $request->validate([
            'operation_key' => ['required', 'uuid'],
        ]);

        try {
            $result = $reports->exportCsv(
                $reportKey,
                $v['operation_key']
            );

            return response($result['csv'], 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="cod-daily-'
                    . $result['report_date'] . '.csv"',
                'Cache-Control' => 'private, no-store',
                'X-COD-Export-Replayed' => $result['idempotent_replay'] ? '1' : '0',
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
