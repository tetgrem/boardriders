<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Crm74\Crm74Audit;
use App\Services\Crm74\Crm74Customers;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class Crm74CustomersController extends Controller
{
    public function overview(
        Crm74Customers $customers
    ): JsonResponse {
        return $this->run(
            fn () => $customers->overview()
        );
    }

    public function index(
        Request $request,
        Crm74Customers $customers,
        Crm74Audit $audit
    ): JsonResponse {
        $audit->actor();

        $filters = $request->validate([
            ...$this->pageRules(),

            'q' => ['sometimes', 'string', 'max:64'],

            'segment' => [
                'sometimes',
                'in:all,prospect,new,regular,vip,inactive',
            ],
        ]);

        return $this->run(
            fn () => $customers->directory($filters)
        );
    }

    public function show(
        int $customerId,
        Crm74Customers $customers
    ): JsonResponse {
        return $this->run(
            fn () => $customers->profile($customerId)
        );
    }

    public function section(
        Request $request,
        int $customerId,
        string $section,
        Crm74Customers $customers,
        Crm74Audit $audit
    ): JsonResponse {
        $audit->actor();

        $rules = $this->pageRules();

        if ($section === 'orders') {
            $rules['payment_status'] = [
                'sometimes',
                'in:pending,paid,review',
            ];
        }

        $filters = $request->validate($rules);

        return $this->run(fn () => match ($section) {
            'orders' => $customers->orders(
                $customerId,
                $filters
            ),

            'returns' => $customers->returns(
                $customerId,
                $filters
            ),

            'wallets' => $customers->wallets(
                $customerId,
                $filters
            ),

            'history' => $customers->history(
                $customerId,
                $filters
            ),

            'access' => $customers->history(
                $customerId,
                $filters,
                true
            ),

            default => abort(404),
        });
    }

    public function ledger(
        Request $request,
        int $customerId,
        int $walletId,
        Crm74Customers $customers,
        Crm74Audit $audit
    ): JsonResponse {
        $audit->actor();

        $filters = $request->validate(
            $this->pageRules()
        );

        return $this->run(
            fn () => $customers->ledger(
                $customerId,
                $walletId,
                $filters
            )
        );
    }

    public function write(
        Request $request,
        int $customerId,
        Crm74Audit $audit
    ): JsonResponse {
        $audit->actor(true);

        $base = $request->validate([
            'operation_key' => ['required', 'uuid'],

            'kind' => [
                'required',
                'in:contact,note,call,message,complaint,offer',
            ],
        ]);

        $kind = $base['kind'];

        if ($kind === 'contact') {
            $payload = $request->validate([
                'expected_contact_id' => [
                    'present',
                    'nullable',
                    'integer',
                    'min:1',
                ],

                'phone' => [
                    'present',
                    'nullable',
                    'string',
                    'regex:/^\+[1-9][0-9]{7,14}$/D',
                ],

                'city' => [
                    'present', 'nullable', 'string', 'max:120',
                ],

                'address' => [
                    'present', 'nullable', 'string', 'max:300',
                ],

                'preferred_channel' => [
                    'required',
                    'in:none,phone,email,telegram,viber,whatsapp',
                ],
            ]);

            $payload['expected_contact_id'] =
                $payload['expected_contact_id'] === null
                    ? null
                    : (int) $payload['expected_contact_id'];

            foreach (['phone', 'city', 'address'] as $field) {
                $payload[$field] = $payload[$field] === null
                    ? null
                    : (trim($payload[$field]) ?: null);
            }
        } else {
            $payload = $request->validate([
                'body' => [
                    'required',
                    'string',
                    'min:2',
                    'max:' . (int) config(
                        'crm74.max_note_length', 1000
                    ),
                ],

                'follow_up_at' => [
                    'nullable',
                    'date_format:Y-m-d\TH:i:s\Z',
                ],
            ]);

            $payload['body'] = trim($payload['body']);

            $payload['follow_up_at'] =
                $payload['follow_up_at'] ?? null;
        }

        return $this->run(
            fn () => $audit->write(
                $customerId,
                $base['operation_key'],
                $kind,
                $payload
            ),
            201
        );
    }

    private function pageRules(): array
    {
        return [
            'limit' => [
                'sometimes', 'integer', 'min:1', 'max:50',
            ],

            'before_id' => [
                'sometimes', 'integer', 'min:1',
            ],
        ];
    }

    private function run(
        callable $callback,
        int $status = 200
    ): JsonResponse {
        try {
            return response()
                ->json($callback(), $status)
                ->header(
                    'Cache-Control',
                    'no-store, private'
                );

        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409)->header('Cache-Control', 'no-store');

        } catch (QueryException $exception) {
            // Do not return SQL or customer data.
            report($exception);

            return response()->json([
                'message' => 'Customer CRM database error',
            ], 503)->header('Cache-Control', 'no-store');

        } catch (RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            report($exception);

            return response()->json([
                'message' => 'Customer CRM integrity error',
            ], 503)->header('Cache-Control', 'no-store');
        }
    }
}
