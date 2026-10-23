<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JsonException;
use SplFileObject;
use Throwable;

class ImportProductCatalog extends Command
{
    protected $signature = 'catalog:import {file : Path to a UTF-8 JSONL product export}';

    protected $description = 'Import product data into the catalog as unpublished draft products';

    public function handle(): int
    {
        if (! Schema::hasTable('san_pham') || ! Schema::hasColumn('san_pham', 'source_url')) {
            $this->components->error('Run php artisan migrate before importing products.');

            return self::FAILURE;
        }

        $path = $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            $this->components->error("Import file is not readable: {$path}");

            return self::FAILURE;
        }

        $file = new SplFileObject($path, 'r');
        $batch = [];
        $imported = 0;
        $skipped = 0;

        while (! $file->eof()) {
            $line = $file->fgets();
            if (trim($line) === '') {
                continue;
            }

            try {
                $source = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $this->components->error('Invalid JSON on line '.$file->key().': '.$exception->getMessage());

                return self::FAILURE;
            }

            $product = $this->mapProduct($source, $file->key());
            if ($product === null) {
                $skipped++;

                continue;
            }

            $batch[] = $product;
            if (count($batch) >= 200) {
                if (! $this->saveBatch($batch)) {
                    return self::FAILURE;
                }
                $imported += count($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            if (! $this->saveBatch($batch)) {
                return self::FAILURE;
            }
            $imported += count($batch);
        }

        $this->components->info("Imported or updated {$imported} products as draft; skipped {$skipped} invalid rows.");
        $this->line('Imported products remain hidden until price, stock, and image rights have been verified.');

        return self::SUCCESS;
    }

    private function mapProduct(mixed $source, int $line): ?array
    {
        if (! is_array($source)) {
            $this->components->warn("Skipped line {$line}: expected a JSON object.");

            return null;
        }

        $url = trim((string) ($source['url'] ?? ''));
        $name = trim((string) ($source['name'] ?? ''));
        $category = trim((string) ($source['category'] ?? ''));

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            || mb_strlen($url) > 512 || $name === '' || mb_strlen($name) > 255
            || $category === '' || mb_strlen($category) > 100) {
            $this->components->warn("Skipped line {$line}: missing or invalid product URL, name, or category.");

            return null;
        }

        $id = strtoupper(substr(hash('sha256', $url), 0, 10));
        $slugSource = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
        $slug = Str::limit(Str::slug($slugSource ?: $name).'-'.$id, 255, '');
        $specifications = array_filter(
            $source['specifications'] ?? [],
            static fn ($value) => is_scalar($value) && trim((string) $value) !== '',
        );

        $product = [
            'ma_sp' => $id,
            'ten_sp' => $name,
            'loai' => $category,
            'thuong_hieu' => $this->text($source['brand'] ?? null, 100),
            'mo_ta' => $this->text($source['description'] ?? null, 255),
            'gia_ban' => $this->price($source['sale_price'] ?? null),
            'gia_niem_yet' => $this->price($source['list_price'] ?? null),
            'so_luong' => 0,
            'stt' => $line,
            'slug' => $slug,
            'trang_thai_hien_thi' => 'draft',
            'source_url' => $url,
            'thong_so_ky_thuat' => json_encode($specifications, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
        if (Schema::hasColumn('san_pham', 'la_hang_cu')) {
            $product['la_hang_cu'] = preg_match('/\s-\s*cũ(?:\s|$)/iu', $name) === 1;
        }

        return $product;
    }

    private function saveBatch(array $batch): bool
    {
        try {
            $updateColumns = [
                'ten_sp',
                'loai',
                'thuong_hieu',
                'mo_ta',
                'gia_ban',
                'gia_niem_yet',
                'slug',
                'thong_so_ky_thuat',
            ];
            DB::table('san_pham')->upsert($batch, ['source_url'], $updateColumns);
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('Could not import product batch: '.$exception->getMessage());

            return false;
        }

        return true;
    }

    private function text(mixed $value, int $maxLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    private function price(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $price = (float) $value;
        if (! is_finite($price) || $price < 0 || $price > 9_999_999_999) {
            return null;
        }

        return (int) round($price);
    }
}
