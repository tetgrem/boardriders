<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Catalog71\Catalog71Directories;
use App\Services\Catalog71\Catalog71Products;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class Catalog71Controller extends Controller
{
    private const SLUG = 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    public function index(
        Request $request,
        Catalog71Products $products
    ): JsonResponse {
        $products->actor();

        $data = $request->validate([
            'q' => ['sometimes', 'string', 'max:64'],
            'status' => ['sometimes', 'in:all,draft,active,archived'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        return $this->run(fn () => $products->page($data));
    }

    public function show(
        int $productId,
        Catalog71Products $products
    ): JsonResponse {
        return $this->run(fn () => $products->show($productId));
    }

    public function create(
        Request $request,
        Catalog71Products $products
    ): JsonResponse {
        $products->actor();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', self::SLUG],
        ]);

        return $this->run(fn () => $products->create($data), 201);
    }

    public function change(
        Request $request,
        int $productId,
        Catalog71Products $products
    ): JsonResponse {
        $products->actor();

        $actions = 'details,translation,status,attribute,attribute_remove,'
            . 'variant_create,variant_update,image_upload,image_update,image_remove';

        $base = $request->validate([
            'action' => ['required', 'in:batch,' . $actions],
            'expected_revision' => [
                'required', 'integer', 'min:0', 'max:2147483646',
            ],
        ]);

        if ($base['action'] === 'batch') {
            $input = $request->validate([
                'changes' => ['required', 'array', 'min:1', 'max:500'],
                'changes.*.action' => ['required', 'in:' . $actions],
                'changes.*.data' => ['required', 'array'],
            ]);

            $changes = [];
            $imageBytes = 0;

            foreach ($input['changes'] as $change) {
                $data = \Illuminate\Support\Facades\Validator::make(
                    $change['data'],
                    $this->actionRules($change['action'])
                )->validate();

                if ($change['action'] === 'image_upload') {
                    $imageBytes += strlen($data['base64']);

                    if ($imageBytes > 5592408) {
                        abort(422, 'Combined image size limit exceeded');
                    }
                }

                $changes[] = [
                    'action' => $change['action'],
                    'data' => $data,
                ];
            }

            return $this->run(fn () => $products->changeBatch(
                $productId,
                (int) $base['expected_revision'],
                $changes
            ));
        }

        $data = $request->validate(
            $this->actionRules($base['action'])
        );

        return $this->run(fn () => $products->change(
            $productId,
            $base['action'],
            (int) $base['expected_revision'],
            $data
        ));
    }

    private function actionRules(string $action): array
    {
        return match ($action) {
            'details' => [
                'brand_id' => ['present', 'nullable', 'integer', 'min:1'],
                'category_ids' => ['present', 'array', 'max:30'],
                'category_ids.*' => ['integer', 'min:1', 'distinct'],
                'primary_category_id' => [
                    'present', 'nullable', 'integer', 'min:1',
                ],
            ],

            'translation' => [
                'locale' => ['required', 'in:uk,ru,en'],
                'name' => ['required', 'string', 'max:255'],
                'slug' => ['nullable', 'string', 'max:255', self::SLUG],
                'short_description' => ['nullable', 'string', 'max:2000'],
                'description' => ['nullable', 'string', 'max:30000'],
                'seo_title' => ['nullable', 'string', 'max:255'],
                'seo_description' => ['nullable', 'string', 'max:320'],
            ],

            'status' => [
                'status' => ['required', 'in:draft,active,archived'],
            ],

            'attribute' => [
                'attribute_id' => ['required', 'integer', 'min:1'],
                'value' => ['present'],
            ],

            'attribute_remove' => [
                'attribute_id' => ['required', 'integer', 'min:1'],
            ],

            'variant_create' => [
                'sku' => [
                    'nullable', 'string', 'max:100',
                    'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/D',
                ],
                'barcode' => ['nullable', 'string', 'max:100'],
                'weight_grams' => [
                    'nullable', 'integer', 'min:1', 'max:1000000',
                ],
                'sort_order' => [
                    'sometimes', 'integer', 'min:0', 'max:1000000',
                ],
                'option_ids' => ['present', 'array', 'max:20'],
                'option_ids.*' => ['integer', 'min:1', 'distinct'],
            ],

            'variant_update' => [
                'variant_id' => ['required', 'integer', 'min:1'],
                'barcode' => ['nullable', 'string', 'max:100'],
                'is_active' => ['required', 'boolean'],
                'sort_order' => [
                    'required', 'integer', 'min:0', 'max:1000000',
                ],
                'weight_grams' => [
                    'nullable', 'integer', 'min:1', 'max:1000000',
                ],
            ],

            'image_upload' => [
                'base64' => ['required', 'string', 'max:2796204'],
                'alt_uk' => ['nullable', 'string', 'max:255'],
                'alt_ru' => ['nullable', 'string', 'max:255'],
            ],

            'image_update' => [
                'image_id' => ['required', 'integer', 'min:1'],
                'is_primary' => ['required', 'boolean'],
                'sort_order' => [
                    'required', 'integer', 'min:0', 'max:1000000',
                ],
                'alt_uk' => ['nullable', 'string', 'max:255'],
                'alt_ru' => ['nullable', 'string', 'max:255'],
            ],

            'image_remove' => [
                'image_id' => ['required', 'integer', 'min:1'],
            ],
        };
    }

    public function history(
        int $productId,
        Catalog71Products $products
    ): JsonResponse {
        return $this->run(fn () => $products->history($productId));
    }

    public function lookups(
        Catalog71Directories $directories
    ): JsonResponse {
        return $this->run(fn () => $directories->lookups());
    }

    public function directory(
        Request $request,
        string $kind,
        Catalog71Products $products,
        Catalog71Directories $directories
    ): JsonResponse {
        $products->actor();

        $data = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'expected_revision' => [
                'required', 'integer', 'min:0', 'max:2147483646',
            ],
            'name' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:200', self::SLUG],
            'is_active' => ['required', 'boolean'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'sort_order' => [
                'sometimes', 'integer', 'min:0', 'max:1000000',
            ],
            'description' => ['nullable', 'string', 'max:10000'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:320'],
        ]);

        return $this->run(fn () => $directories->save($kind, $data));
    }

    public function directoryHistory(
        string $kind,
        int $entityId,
        Catalog71Directories $directories
    ): JsonResponse {
        return $this->run(
            fn () => $directories->history($kind, $entityId)
        );
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
            if ((string) $exception->getCode() === '23505') {
                return response()->json([
                    'message' => 'Duplicate SKU, barcode or slug',
                ], 409)->header('Cache-Control', 'no-store');
            }

            report($exception);

            return response()->json([
                'message' => 'Catalog database operation failed',
            ], 503)->header('Cache-Control', 'no-store');

        } catch (RuntimeException $exception) {
            // Authorization and HTTP exceptions must retain
            // their original status codes.
            if ($exception instanceof
                \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $exception instanceof
                \Illuminate\Auth\Access\AuthorizationException) {
                throw $exception;
            }

            report($exception);

            return response()->json([
                'message' => 'Catalog integrity or storage check failed',
            ], 503)->header('Cache-Control', 'no-store');
        }
    }
}
