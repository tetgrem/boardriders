<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing73\Campaigns731;
use App\Services\Pricing73\Pricing73Audit;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class Campaign731Controller extends Controller
{
    public function index(
        Request $request,
        Campaigns731 $campaigns
    ): JsonResponse {
        $data = $request->validate([
            'before_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        return $this->run(
            fn () => $campaigns->listing($data)
        );
    }

    public function variants(
        Request $request,
        int $campaignId,
        Campaigns731 $campaigns
    ): JsonResponse {
        $data = $request->validate([
            'before_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        return $this->run(
            fn () => $campaigns->variants($campaignId, $data)
        );
    }

    public function preview(
        Request $request,
        Pricing73Audit $audit,
        Campaigns731 $campaigns
    ): JsonResponse {
        $audit->actor(true);

        $data = $this->terms($request);

        return $this->run(
            fn () => $campaigns->preview($data)
        );
    }

    public function create(
        Request $request,
        Pricing73Audit $audit,
        Campaigns731 $campaigns
    ): JsonResponse {
        $audit->actor(true);

        $base = $request->validate([
            'operation_key' => ['required', 'uuid'],
            'preview_token' => [
                'required',
                'string',
                'regex:/^[a-f0-9]{64}$/D',
            ],
        ]);

        $data = $this->terms($request);

        return $this->run(
            fn () => $campaigns->create(
                $base['operation_key'],
                $data,
                $base['preview_token']
            ),
            201
        );
    }

    public function toggle(
        Request $request,
        Pricing73Audit $audit,
        Campaigns731 $campaigns
    ): JsonResponse {
        $audit->actor(true);

        $data = $request->validate([
            'operation_key' => ['required', 'uuid'],
            'campaign_id' => ['required', 'integer', 'min:1'],
            'expected_revision' => [
                'required', 'integer', 'min:1', 'max:2147483646',
            ],
            'is_active' => ['required', 'boolean'],
        ]);

        $key = $data['operation_key'];

        unset($data['operation_key']);

        return $this->run(
            fn () => $campaigns->toggle($key, $data)
        );
    }

    private function terms(Request $request): array
    {
        $data = $request->validate([
            'price_list_id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:160'],
            'badge' => ['required', 'string', 'max:32'],

            'discount_type' => ['required', 'in:percent,fixed'],

            'discount_value' => [
                'required', 'integer', 'min:1', 'max:100000000000',
            ],

            'max_discount_minor' => [
                'nullable', 'integer', 'min:1', 'max:100000000000',
            ],

            'priority' => [
                'required', 'integer', 'min:-1000000', 'max:1000000',
            ],

            'allow_promo' => ['required', 'boolean'],
            'allow_loyalty' => ['required', 'boolean'],

            'scope_type' => [
                'required', 'in:all,brand,category,product,variant',
            ],

            'scope_ids' => ['present', 'array', 'max:500'],
            'scope_ids.*' => ['integer', 'min:1', 'distinct'],

            'excluded_variant_ids' => ['present', 'array', 'max:500'],
            'excluded_variant_ids.*' => ['integer', 'min:1', 'distinct'],

            'max_stock' => [
                'nullable', 'integer', 'min:1', 'max:1000000',
            ],

            'unsold_days' => [
                'nullable', 'integer', 'min:1', 'max:3650',
            ],

            'starts_at' => [
                'required', 'date_format:Y-m-d\TH:i:s\Z',
            ],

            'ends_at' => [
                'required', 'date_format:Y-m-d\TH:i:s\Z',
            ],
        ]);

        foreach ([
                     'price_list_id',
                     'discount_value',
                     'priority',
                     'max_discount_minor',
                     'max_stock',
                     'unsold_days',
                 ] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }

        foreach ([
                     'scope_ids',
                     'excluded_variant_ids',
                 ] as $field) {
            $data[$field] = array_values(array_map(
                'intval',
                $data[$field]
            ));

            sort($data[$field], SORT_NUMERIC);
        }

        $data['allow_promo'] = (bool) $data['allow_promo'];
        $data['allow_loyalty'] = (bool) $data['allow_loyalty'];

        return $data;
    }

    private function run(
        callable $callback,
        int $status = 200
    ): JsonResponse {
        try {
            return response()->json($callback(), $status)
                ->header('Cache-Control', 'no-store');

        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409)->header('Cache-Control', 'no-store');

        } catch (QueryException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Campaign database operation failed',
            ], 503)->header('Cache-Control', 'no-store');

        } catch (RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            report($exception);

            return response()->json([
                'message' => 'Campaign integrity check failed',
            ], 503)->header('Cache-Control', 'no-store');
        }
    }
}
