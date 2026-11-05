<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class AddProductPhoto extends Command
{
    protected $signature = 'catalog:photo:add
        {slug : Локалізований slug товару}
        {file : Абсолютний шлях до фото}
        {--locale=uk : uk або ru}
        {--primary : Зробити головним фото товару}
        {--alt-uk= : Опис зображення українською}
        {--alt-ru= : Опис зображення російською}
        {--sort= : Порядок у галереї}';

    protected $description =
        'Додати фото товару (тільки local/testing).';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Команда доступна лише в local/testing.');

            return self::FAILURE;
        }

        $locale = (string) $this->option('locale');

        if (! in_array($locale, ['uk', 'ru'], true)) {
            $this->error('Мова має бути uk або ru.');

            return self::FAILURE;
        }

        $filePath = (string) $this->argument('file');

        if (! is_file($filePath) || ! is_readable($filePath)) {
            $this->error("Файл не знайдено: {$filePath}");

            return self::FAILURE;
        }

        $size = filesize($filePath);

        if ($size === false || $size === 0 || $size > 8 * 1024 * 1024) {
            $this->error('Допустимий розмір фото — до 8 МБ.');

            return self::FAILURE;
        }

        $image = @getimagesize($filePath);
        $mime = $image['mime'] ?? null;

        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if ($image === false || ! isset($extensions[$mime])) {
            $this->error('Підтримуються лише JPEG, PNG та WebP.');

            return self::FAILURE;
        }

        if (
            $image[0] < 1 ||
            $image[1] < 1 ||
            $image[0] > 8000 ||
            $image[1] > 8000
        ) {
            $this->error('Максимальний розмір — 8000 × 8000 px.');

            return self::FAILURE;
        }

        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))
            ->file($filePath);

        if ($detectedMime !== $mime) {
            $this->error('Тип файлу не відповідає його вмісту.');

            return self::FAILURE;
        }

        $slug = trim((string) $this->argument('slug'));

        $productId = DB::table('product_translations')
            ->where('slug', $slug)
            ->where('locale', $locale)
            ->value('product_id');

        if ($productId === null) {
            $this->error("Товар '{$slug}' не знайдено.");

            return self::FAILURE;
        }

        $productId = (int) $productId;

        $names = DB::table('product_translations')
            ->where('product_id', $productId)
            ->pluck('name', 'locale');

        $altUk = trim((string) (
        $this->option('alt-uk')
            ?: ($names['uk'] ?? "Товар {$productId}")
        ));

        $altRu = trim((string) (
        $this->option('alt-ru')
            ?: ($names['ru'] ?? $altUk)
        ));

        if (mb_strlen($altUk) > 255 || mb_strlen($altRu) > 255) {
            $this->error('Підпис фото може містити до 255 символів.');

            return self::FAILURE;
        }

        $sortInput = $this->option('sort');

        if (
            $sortInput !== null &&
            (
                ! ctype_digit((string) $sortInput) ||
                (int) $sortInput > 100000
            )
        ) {
            $this->error('--sort має бути числом від 0 до 100000.');

            return self::FAILURE;
        }

        $disk = Storage::disk('public');

        $filename = Str::uuid()->toString()
            .'.'
            .$extensions[$mime];

        $savedPath = $disk->putFileAs(
            'products/'.$productId,
            new File($filePath),
            $filename,
            'public'
        );

        if (! is_string($savedPath) || $savedPath === '') {
            $this->error('Не вдалося зберегти файл.');

            return self::FAILURE;
        }

        try {
            $result = DB::transaction(function () use (
                $productId,
                $savedPath,
                $altUk,
                $altRu,
                $sortInput
            ): array {
                DB::table('products')
                    ->where('id', $productId)
                    ->lockForUpdate()
                    ->first();

                $existing = DB::table('product_images')
                    ->where('product_id', $productId);

                $isPrimary = (bool) $this->option('primary')
                    || ! (clone $existing)->exists();

                if ($isPrimary) {
                    (clone $existing)
                        ->where('is_primary', true)
                        ->update([
                            'is_primary' => false,
                            'updated_at' => now(),
                        ]);
                }

                $sortOrder = $sortInput === null
                    ? (int) (clone $existing)->max('sort_order') + 1
                    : (int) $sortInput;

                $id = DB::table('product_images')->insertGetId([
                    'product_id' => $productId,
                    'path' => $savedPath,
                    'alt_uk' => $altUk,
                    'alt_ru' => $altRu,
                    'is_primary' => $isPrimary,
                    'sort_order' => $sortOrder,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return [
                    'id' => $id,
                    'primary' => $isPrimary,
                ];
            }, 3);
        } catch (Throwable $exception) {
            $disk->delete($savedPath);

            $this->error(
                'Помилка бази даних: '.$exception->getMessage()
            );

            return self::FAILURE;
        }

        $this->info(
            'Фото додано! ID: '.$result['id']
            .($result['primary'] ? ' (ГОЛОВНЕ)' : '')
        );

        $this->line('Шлях: '.$savedPath);
        $this->line('URL: '.url($disk->url($savedPath)));

        return self::SUCCESS;
    }
}
