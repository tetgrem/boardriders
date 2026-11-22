<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Fiscal62\Fiscal62Profiles;
use App\Services\Fiscal62\Fiscal62TicketService;
use App\Services\Fiscal62\Fiscal62StatusService;
use App\Services\Fiscal62\Fiscal62ReportService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class Fiscal62Controller extends Controller
{
    public function profile(
        Request $request,
        int $variantId,
        Fiscal62Profiles $service
    ): JsonResponse {
        $data = $request->validate([
            'fiscal_code' => [
                'required', 'string', 'max:40',
            ],
            'fiscal_name' => [
                'required', 'string', 'min:3', 'max:200',
            ],
            'tax_code' => [
                'required', 'integer', 'min:1', 'max:20',
            ],
        ]);

        return $this->respond(
            fn (): array => $service->approve(
                $variantId,
                $data['fiscal_code'],
                $data['fiscal_name'],
                (int) $data['tax_code']
            ),
            201
        );
    }

    public function prepare(
        int $documentId,
        Fiscal62TicketService $service
    ): JsonResponse {
        return $this->respond(
            fn (): array =>
            $service->prepare($documentId),
            201
        );
    }

    public function send(
        int $ticketId,
        Fiscal62TicketService $service
    ): JsonResponse {
        return $this->respond(
            fn (): array =>
            $service->requestSend($ticketId),
            202
        );
    }

    public function poll(
        int $ticketId,
        Fiscal62StatusService $service
    ): JsonResponse {
        return $this->respond(
            fn (): array =>
            $service->queuePoll($ticketId),
            202
        );
    }

    public function show(
        int $ticketId,
        Fiscal62ReportService $service
    ): JsonResponse {
        return $this->respond(
            fn (): array =>
            $service->ticket($ticketId)
        );
    }

    public function index(
        Request $request,
        Fiscal62ReportService $service
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
        ]);

        return $this->respond(
            fn (): array =>
            $service->queue(
                (int) ($data['limit'] ?? 20),
                isset($data['before_id'])
                    ? (int) $data['before_id']
                    : null
            )
        );
    }

    public function statistics(
        Fiscal62ReportService $service
    ): JsonResponse {
        return $this->respond(
            fn (): array =>
            $service->stats()
        );
    }

    private function respond(
        callable $action,
        int $success = 200
    ): JsonResponse {
        try {
            return response()->json(
                $action(),
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
                    'Fiscal62 audit/provider verification failed',
            ], 503);
        }
    }
}
