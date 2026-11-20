<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Fiscal61\Fiscal61IncidentService;
use App\Services\Fiscal61\Fiscal61PreparationService;
use App\Services\Fiscal61\Fiscal61ReportService;
use App\Services\Fiscal61\Fiscal61SimulationService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

final class Fiscal61Controller extends Controller
{
    public function prepareSale(
        int $captureReceiptId,
        Fiscal61PreparationService $service
    ): JsonResponse {
        return $this->respond(
            fn (): array =>
            $service->prepareSale($captureReceiptId),
            201
        );
    }

    public function prepareRefund(
        int $journalId,
        Fiscal61PreparationService $service
    ): JsonResponse {
        return $this->respond(
            fn (): array =>
            $service->prepareRefund($journalId),
            201
        );
    }

    public function requestPreview(
        int $documentId,
        Fiscal61SimulationService $service
    ): JsonResponse {
        return $this->respond(
            fn (): array =>
            $service->requestPreview($documentId),
            202
        );
    }

    public function showPreview(
        int $documentId,
        Fiscal61SimulationService $service
    ): JsonResponse {
        return $this->respond(
            fn (): array =>
            $service->show($documentId)
        );
    }

    public function showDocument(
        int $documentId,
        Fiscal61ReportService $service
    ): JsonResponse {
        return $this->respond(
            fn (): array =>
            $service->document($documentId)
        );
    }

    public function index(
        Request $request,
        Fiscal61ReportService $service
    ): JsonResponse {
        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'kind' => ['sometimes', 'in:sale,refund'],
        ]);

        return $this->respond(
            fn (): array =>
            $service->listDocuments(
                (int) ($data['limit'] ?? 20),
                isset($data['before_id'])
                    ? (int) $data['before_id']
                    : null,
                $data['kind'] ?? null
            )
        );
    }

    public function statistics(
        Fiscal61ReportService $service
    ): JsonResponse {
        return $this->respond(
            fn (): array =>
            $service->statistics()
        );
    }

    public function recordIncident(
        Request $request,
        int $documentId,
        Fiscal61IncidentService $service
    ): JsonResponse {
        $data = $request->validate([
            'reason_code' => [
                'required',
                'in:missing_tax_mapping,provider_not_configured,original_receipt_missing,amount_discrepancy,manual_review,operator_note',
            ],
            'note' => [
                'required',
                'string',
                'min:4',
                'max:500',
            ],
        ]);

        return $this->respond(
            fn (): array =>
            $service->record(
                $documentId,
                $data['reason_code'],
                $data['note']
            ),
            201
        );
    }

    public function incidents(
        int $documentId,
        Fiscal61IncidentService $service
    ): JsonResponse {
        return $this->respond(
            fn (): array =>
            $service->history($documentId)
        );
    }

    private function respond(
        callable $callback,
        int $successStatus = 200
    ): JsonResponse {
        try {
            return response()->json(
                $callback(),
                $successStatus
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' =>
                    'Fiscal61 audit or source verification failed',
            ], 503);
        }
    }
}
