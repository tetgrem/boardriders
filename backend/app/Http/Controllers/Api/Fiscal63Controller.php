<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Fiscal63\Fiscal63CaseService;
use App\Services\Fiscal63\Fiscal63MonitorService;
use App\Services\Fiscal63\Fiscal63ReportService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class Fiscal63Controller extends Controller
{
    public function scan(
        Request $request,
        Fiscal63MonitorService $service
    ): JsonResponse {
        $data = $request->validate([
            'limit' => [
                'sometimes',
                'integer',
                'min:1',
                'max:500',
            ],
        ]);

        return $this->respond(
            fn () => $service->scan(
                (int) ($data['limit'] ?? 100)
            )
        );
    }

    public function index(
        Request $request,
        Fiscal63ReportService $service
    ): JsonResponse {
        $data = $request->validate([
            'limit' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],
            'before_id' => [
                'sometimes',
                'integer',
                'min:1',
            ],
            'state' => [
                'sometimes',
                'in:open,acknowledged,closed',
            ],
        ]);

        return $this->respond(
            fn () => $service->list(
                (int) ($data['limit'] ?? 20),
                isset($data['before_id'])
                    ? (int) $data['before_id']
                    : null,
                $data['state'] ?? null
            )
        );
    }

    public function dashboard(
        Fiscal63ReportService $service
    ): JsonResponse {
        return $this->respond(
            fn () => $service->dashboard()
        );
    }

    public function show(
        int $caseId,
        Fiscal63CaseService $service
    ): JsonResponse {
        return $this->respond(
            fn () => $service->show($caseId)
        );
    }

    public function acknowledge(
        int $caseId,
        Fiscal63CaseService $service
    ): JsonResponse {
        return $this->respond(
            fn () => $service->acknowledge($caseId)
        );
    }

    public function note(
        Request $request,
        int $caseId,
        Fiscal63CaseService $service
    ): JsonResponse {
        $data = $request->validate([
            'note' => [
                'required',
                'string',
                'min:4',
                'max:500',
            ],
        ]);

        return $this->respond(
            fn () => $service->addNote(
                $caseId,
                $data['note']
            ),
            201
        );
    }

    public function recover(
        int $caseId,
        Fiscal63CaseService $service
    ): JsonResponse {
        return $this->respond(
            fn () => $service->recoverTransmission(
                $caseId
            )
        );
    }

    public function poll(
        int $caseId,
        Fiscal63CaseService $service
    ): JsonResponse {
        return $this->respond(
            fn () => $service->requestPoll(
                $caseId
            ),
            202
        );
    }

    public function close(
        int $caseId,
        Fiscal63CaseService $service
    ): JsonResponse {
        return $this->respond(
            fn () => $service->close($caseId)
        );
    }

    private function respond(
        callable $operation,
        int $success = 200
    ): JsonResponse {
        try {
            return response()->json(
                $operation(),
                $success
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' =>
                    'Fiscal incident audit failed',
            ], 503);
        }
    }
}
