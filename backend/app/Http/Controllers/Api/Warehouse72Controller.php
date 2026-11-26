<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Warehouse72\Warehouse72Audit;
use App\Services\Warehouse72\Warehouse72Documents;
use App\Services\Warehouse72\Warehouse72Read;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class Warehouse72Controller extends Controller
{
    public function overview(Warehouse72Read $read): JsonResponse
    {
        return $this->run(fn () => $read->overview());
    }

    public function listing(
        Request $request,
        string $section,
        Warehouse72Read $read,
        Warehouse72Audit $audit
    ): JsonResponse {
        $audit->actor();

        $rules = [
            'warehouse_id' => [
                $section === 'variants' ? 'required' : 'sometimes',
                'integer',
                'min:1',
            ],
            'q' => ['sometimes', 'string', 'max:64'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];

        if ($section === 'stocks') {
            $rules['stock_status'] = [
                'sometimes',
                'in:all,available,reserved,low,empty',
            ];
        }

        if ($section === 'documents') {
            $rules['kind'] = [
                'sometimes',
                'in:receipt,transfer,stocktake,writeoff,warehouse_create,warehouse_update',
            ];
        }

        $data = $request->validate($rules);

        return $this->run(fn () => match ($section) {
            'stocks' => $read->stocks($data),
            'variants' => $read->variants($data),
            'movements' => $read->movements($data),
            'reservations' => $read->reservations($data),
            'documents' => $read->documents($data),
            default => abort(404),
        });
    }

    public function document(
        int $documentId,
        Warehouse72Read $read
    ): JsonResponse {
        return $this->run(
            fn () => $read->document($documentId)
        );
    }

    public function submit(
        Request $request,
        Warehouse72Audit $audit,
        Warehouse72Documents $documents
    ): JsonResponse {
        $audit->actor(true);

        $base = $request->validate([
            'operation_key' => ['required', 'uuid'],
            'kind' => [
                'required',
                'in:receipt,transfer,stocktake,writeoff,warehouse_create,warehouse_update',
            ],
        ]);

        $kind = $base['kind'];

        if (in_array($kind, [
            'warehouse_create',
            'warehouse_update',
        ], true)) {
            $rules = [
                'name' => ['required', 'string', 'max:255'],
                'country_code' => [
                    'required',
                    'string',
                    'regex:/^[A-Z]{2}$/D',
                ],
                'city' => ['nullable', 'string', 'max:150'],
                'address' => ['nullable', 'string', 'max:500'],
            ];

            if ($kind === 'warehouse_update') {
                $rules['warehouse_id'] = [
                    'required', 'integer', 'min:1',
                ];

                $rules['expected_revision'] = [
                    'required', 'integer', 'min:0', 'max:2147483646',
                ];
            }
        } else {
            $rules = [
                'warehouse_id' => ['required', 'integer', 'min:1'],

                'reference' => [
                    'required',
                    'string',
                    'max:150',
                    function (string $attribute, mixed $value, $fail): void {
                        if (is_string($value)
                            && strlen(trim($value)) > 150) {
                            $fail('Document reference exceeds 150 bytes.');
                        }
                    },
                ],

                'note' => ['nullable', 'string', 'max:1000'],

                'lines' => [
                    'required',
                    'array',
                    'min:1',
                    'max:' . (int) config(
                        'warehouse72.max_document_lines',
                        50
                    ),
                ],

                'lines.*' => [
                    'required',
                    'array:variant_id,quantity,expected_revision',
                ],

                'lines.*.variant_id' => [
                    'required', 'integer', 'min:1', 'distinct',
                ],

                'lines.*.quantity' => [
                    'required',
                    'integer',
                    'min:' . ($kind === 'stocktake' ? '0' : '1'),
                    'max:' . (int) config(
                        'warehouse72.max_line_quantity',
                        1000000
                    ),
                ],
            ];

            if ($kind === 'transfer') {
                $rules['to_warehouse_id'] = [
                    'required',
                    'integer',
                    'min:1',
                    'different:warehouse_id',
                ];
            }

            if (in_array($kind, ['stocktake', 'writeoff'], true)) {
                $rules['lines.*.expected_revision'] = [
                    'required',
                    'integer',
                    'min:0',
                ];
            }

            if ($kind === 'writeoff') {
                $rules['reason'] = [
                    'required',
                    'in:damage,loss,correction',
                ];

                $rules['note'] = [
                    'required',
                    'string',
                    'min:3',
                    'max:1000',
                ];
            }
        }

        $data = $request->validate($rules);

        // Normalize numeric fields before signing.
        foreach ([
                     'warehouse_id',
                     'to_warehouse_id',
                     'expected_revision',
                 ] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }

        if (isset($data['lines'])) {
            $data['lines'] = array_values(array_map(
                static function (array $line): array {
                    $normalized = [
                        'variant_id' => (int) $line['variant_id'],
                        'quantity' => (int) $line['quantity'],
                    ];

                    if (isset($line['expected_revision'])) {
                        $normalized['expected_revision'] =
                            (int) $line['expected_revision'];
                    }

                    return $normalized;
                },
                $data['lines']
            ));
        }

        return $this->run(
            fn () => $documents->submit([
                ...$base,
                ...$data,
            ]),
            201
        );
    }

    private function run(
        callable $callback,
        int $status = 200
    ): JsonResponse {
        try {
            return response()->json($callback(), $status)
                ->header('Cache-Control', 'no-store');

        } catch (DomainException | InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409)->header('Cache-Control', 'no-store');

        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23505') {
                return response()->json([
                    'message' => 'Складской документ уже существует',
                ], 409)->header('Cache-Control', 'no-store');
            }

            report($exception);

            return response()->json([
                'message' => 'Warehouse database operation failed',
            ], 503)->header('Cache-Control', 'no-store');

        } catch (RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            report($exception);

            return response()->json([
                'message' => 'Warehouse integrity check failed',
            ], 503)->header('Cache-Control', 'no-store');
        }
    }
}
