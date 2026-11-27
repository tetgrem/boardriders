<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing73\Pricing73Audit;
use App\Services\Pricing73\Pricing73Commands;
use App\Services\Pricing73\Pricing73Read;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class Pricing73Controller extends Controller
{
    public function overview(Pricing73Read $read): JsonResponse
    {
        return $this->run(fn () => $read->overview());
    }

    public function listing(
        Request $request,
        string $section,
        Pricing73Read $read,
        Pricing73Audit $audit
    ): JsonResponse {
        $audit->actor();

        $rules = [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'q' => ['sometimes', 'string', 'max:64'],
            'price_list_id' => [
                $section === 'prices' ? 'required' : 'sometimes',
                'integer',
                'min:1',
            ],
        ];

        if ($section === 'prices') {
            $rules['price_status'] = [
                'sometimes',
                'in:all,missing',
            ];
        }

        if ($section === 'scopes') {
            $rules['scope_type'] = [
                'required',
                'in:brand,category,product,variant',
            ];
        }

        $data = $request->validate($rules);

        return $this->run(fn () => match ($section) {
            'prices' => $read->prices($data),
            'promotions' => $read->promotions($data),
            'scopes' => $read->scopes($data),
            'history' => $read->history($data),
            default => abort(404),
        });
    }

    public function promotion(
        int $promotionId,
        Pricing73Read $read
    ): JsonResponse {
        return $this->run(
            fn () => $read->promotion($promotionId)
        );
    }

    public function policies(
        int $marketId,
        Pricing73Read $read
    ): JsonResponse {
        return $this->run(
            fn () => $read->policies($marketId)
        );
    }

    public function submit(
        Request $request,
        Pricing73Audit $audit,
        Pricing73Commands $commands
    ): JsonResponse {
        $audit->actor(true);

        $base = $request->validate([
            'operation_key' => ['required', 'uuid'],
            'action' => [
                'required',
                'in:prices_save,promotion_create,promotion_toggle,'
                . 'code_create,code_toggle,market_create,policy_create',
            ],
        ]);

        $max = (int) config(
            'pricing73.max_amount_minor',
            100000000000
        );

        $promotionBase = [
            'promotion_id' => ['required', 'integer', 'min:1'],
            'expected_revision' => [
                'required', 'integer', 'min:0', 'max:2147483646',
            ],
        ];

        $codeRules = [
            'nullable',
            'string',
            'max:40',
            'regex:/^[A-Za-z0-9][A-Za-z0-9_-]{2,39}$/D',
        ];

        $positiveLimit = [
            'nullable', 'integer', 'min:1', 'max:1000000000',
        ];

        $rules = match ($base['action']) {
            'prices_save' => [
                'price_list_id' => ['required', 'integer', 'min:1'],

                'lines' => [
                    'required',
                    'array',
                    'min:1',
                    'max:' . (int) config('pricing73.max_price_lines', 100),
                ],

                'lines.*' => [
                    'required',
                    'array:variant_id,price_minor,compare_at_minor,expected_revision',
                ],

                'lines.*.variant_id' => [
                    'required', 'integer', 'min:1', 'distinct',
                ],

                'lines.*.price_minor' => [
                    'required', 'integer', 'min:0', 'max:' . $max,
                ],

                'lines.*.compare_at_minor' => [
                    'present', 'nullable', 'integer', 'min:1', 'max:' . $max,
                ],

                'lines.*.expected_revision' => [
                    'required', 'integer', 'min:0', 'max:2147483646',
                ],
            ],

            'promotion_create' => [
                'price_list_id' => ['required', 'integer', 'min:1'],
                'name' => ['required', 'string', 'max:160'],
                'type' => ['required', 'in:percent,fixed'],
                'value' => ['required', 'integer', 'min:1', 'max:' . $max],
                'min_subtotal_minor' => [
                    'sometimes', 'integer', 'min:0', 'max:' . $max,
                ],
                'max_discount_minor' => [
                    'nullable', 'integer', 'min:1', 'max:' . $max,
                ],
                'usage_limit' => $positiveLimit,
                'per_customer_limit' => $positiveLimit,
                'code_usage_limit' => $positiveLimit,
                'code' => $codeRules,
                'starts_at' => [
                    'nullable', 'date_format:Y-m-d\TH:i:s\Z',
                ],
                'ends_at' => [
                    'nullable', 'date_format:Y-m-d\TH:i:s\Z',
                ],
            ],

            'promotion_toggle' => [
                ...$promotionBase,
                'is_active' => ['required', 'boolean'],
            ],

            'code_create' => [
                ...$promotionBase,
                'code' => $codeRules,
                'usage_limit' => $positiveLimit,
            ],

            'code_toggle' => [
                ...$promotionBase,
                'code_id' => ['required', 'integer', 'min:1'],
                'is_active' => ['required', 'boolean'],
            ],

            'market_create' => [
                'price_list_id' => ['required', 'integer', 'min:1'],
                'code' => [
                    'required',
                    'string',
                    'regex:/^[A-Z][A-Z0-9_]{1,15}$/D',
                ],
                'name' => ['required', 'string', 'max:120'],
            ],

            'policy_create' => [
                'market_id' => ['required', 'integer', 'min:1'],

                'expected_policy_id' => [
                    'present', 'nullable', 'integer', 'min:1',
                ],

                'program_code' => ['required', 'string', 'max:64'],
                'is_enabled' => ['required', 'boolean'],

                'bonus_units_per_step' => [
                    'required', 'integer', 'min:1', 'max:1000000000',
                ],

                'money_minor_per_step' => [
                    'required', 'integer', 'min:1', 'max:' . $max,
                ],

                'max_goods_share_bps' => [
                    'required', 'integer', 'min:0', 'max:10000',
                ],

                'max_discount_minor' => [
                    'nullable', 'integer', 'min:0', 'max:' . $max,
                ],

                'minimum_cash_minor' => [
                    'required', 'integer', 'min:0', 'max:' . $max,
                ],

                'rules' => [
                    'present', 'array',
                    'max:' . (int) config('pricing73.max_policy_rules', 50),
                ],

                'rules.*' => [
                    'required',
                    'array:scope_type,scope_id,decision,priority',
                ],

                'rules.*.scope_type' => [
                    'required',
                    'in:all,brand,category,product,variant',
                ],

                'rules.*.scope_id' => [
                    'present', 'nullable', 'integer', 'min:1',
                ],

                'rules.*.decision' => [
                    'required', 'in:allow,deny',
                ],

                'rules.*.priority' => [
                    'required', 'integer', 'min:-1000000', 'max:1000000',
                ],
            ],
        };

        $data = $request->validate($rules);

        if ($base['action'] === 'promotion_create'
            && isset($data['code_usage_limit'])) {
            $data['code_usage_limit'] = (int) $data['code_usage_limit'];
        }

        return $this->run(
            fn () => $commands->submit(
                $base['operation_key'],
                $base['action'],
                $data
            ),
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
                    'message' => 'Запись с таким кодом уже существует',
                ], 409)->header('Cache-Control', 'no-store');
            }

            report($exception);

            return response()->json([
                'message' => 'Pricing database operation failed',
            ], 503)->header('Cache-Control', 'no-store');

        } catch (RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            report($exception);

            return response()->json([
                'message' => 'Pricing integrity check failed',
            ], 503)->header('Cache-Control', 'no-store');
        }
    }
}
