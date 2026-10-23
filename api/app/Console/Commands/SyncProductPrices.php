<?php

namespace App\Console\Commands;

use DOMDocument;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SyncProductPrices extends Command
{
    protected $signature = 'catalog:sync-prices
        {--apply : Save verified current prices to MySQL (default is preview only)}
        {--limit=10 : Maximum products to inspect in this run}
        {--all : Inspect every product currently missing a sale price}
        {--delay=1000 : Delay in milliseconds between requests}';

    protected $description = 'Check missing catalog prices against their saved CellphoneS product URLs';

    public function handle(): int
    {
        if (! Schema::hasTable('san_pham')
            || ! Schema::hasColumn('san_pham', 'source_url')
            || ! Schema::hasColumn('san_pham', 'gia_ban')) {
            $this->components->error('Run php artisan migrate before synchronizing product prices.');

            return self::FAILURE;
        }

        $delay = filter_var($this->option('delay'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($delay === false || $delay < 0 || $limit === false || $limit < 1) {
            $this->components->error('The delay must be non-negative and the limit must be a positive integer.');

            return self::FAILURE;
        }

        $query = DB::table('san_pham')
            ->where(function ($query): void {
                $query->whereNull('gia_ban')->orWhere('gia_ban', '<=', 0);
            })
            ->whereNotNull('source_url')
            ->where('source_url', '!=', '')
            ->orderBy('ma_sp');

        if (! $this->option('all')) {
            $query->limit($limit);
        }

        $products = $query->get(['ma_sp', 'ten_sp', 'source_url']);
        $checked = 0;
        $found = 0;
        $updated = 0;
        $unavailable = 0;
        $failures = 0;
        $apply = (bool) $this->option('apply');

        foreach ($products as $product) {
            if (! $this->isAllowedSource($product->source_url)) {
                $this->components->warn("Skipped {$product->ma_sp}: source URL is not a CellphoneS product URL.");
                $unavailable++;

                continue;
            }

            $checked++;
            try {
                $response = Http::accept('text/html')
                    ->withUserAgent('TechStoreCatalogPriceCheck/1.0')
                    ->timeout(20)
                    ->get($product->source_url);
            } catch (Throwable $exception) {
                report($exception);
                $this->components->warn("Could not fetch {$product->ma_sp}: {$exception->getMessage()}");
                $failures++;
                $this->pause($delay);

                continue;
            }

            if (! $response->successful()) {
                $this->components->warn("Source returned HTTP {$response->status()} for {$product->ma_sp}.");
                $unavailable++;
                if (in_array($response->status(), [403, 429], true)) {
                    $this->components->error('The source is blocking or rate-limiting requests; stopping to avoid further requests.');
                    $failures++;

                    break;
                }
                $this->pause($delay);

                continue;
            }

            $price = $this->productPrice($response->body(), $product->source_url);
            if ($price === null) {
                $this->components->warn("No verified VND offer found for {$product->ma_sp} ({$product->ten_sp}).");
                $unavailable++;
                $this->pause($delay);

                continue;
            }

            $found++;
            $this->line("{$product->ma_sp} | {$product->ten_sp} | {$price} VND");
            if ($apply) {
                $updated += DB::table('san_pham')
                    ->where('ma_sp', $product->ma_sp)
                    ->where(function ($query): void {
                        $query->whereNull('gia_ban')->orWhere('gia_ban', '<=', 0);
                    })
                    ->update(['gia_ban' => $price]);
            }

            $this->pause($delay);
        }

        $this->components->info(sprintf(
            '%s: checked %d, prices found %d, prices saved %d, unavailable %d, request failures %d.',
            $apply ? 'Price sync complete' : 'Preview complete; MySQL was not changed',
            $checked,
            $found,
            $updated,
            $unavailable,
            $failures,
        ));

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function isAllowedSource(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && in_array(strtolower($parts['host'] ?? ''), ['cellphones.com.vn', 'www.cellphones.com.vn'], true)
            && isset($parts['path'])
            && str_ends_with(strtolower($parts['path']), '.html');
    }

    private function productPrice(string $html, string $sourceUrl): ?int
    {
        $sourcePath = $this->normalizedPath($sourceUrl);
        if ($sourcePath === null) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $canonicalNode = $xpath->query('//link[@rel="canonical"]/@href')->item(0);
        if (! $canonicalNode || $this->normalizedPath($canonicalNode->nodeValue) !== $sourcePath) {
            return null;
        }

        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            try {
                $data = json_decode($script->textContent, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            foreach ($this->walk($data) as $node) {
                if (! $this->isProduct($node)) {
                    continue;
                }

                foreach ($this->offers($node['offers'] ?? []) as $offer) {
                    if (($offer['priceCurrency'] ?? null) !== 'VND'
                        || $this->normalizedPath((string) ($offer['url'] ?? '')) !== $sourcePath) {
                        continue;
                    }

                    $rawPrice = $offer['price'] ?? $offer['priceSpecification']['price'] ?? null;
                    if (is_numeric($rawPrice) && is_finite((float) $rawPrice)
                        && (float) $rawPrice > 0 && (float) $rawPrice <= 9_999_999_999) {
                        return (int) round((float) $rawPrice);
                    }
                }
            }
        }

        return null;
    }

    private function normalizedPath(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! in_array(strtolower($parts['host'] ?? ''), ['cellphones.com.vn', 'www.cellphones.com.vn'], true)
            || ! isset($parts['path'])) {
            return null;
        }

        return rtrim(strtolower($parts['path']), '/');
    }

    private function walk(mixed $value): iterable
    {
        if (! is_array($value)) {
            return;
        }

        yield $value;
        foreach ($value as $child) {
            if (is_array($child)) {
                yield from $this->walk($child);
            }
        }
    }

    private function offers(mixed $offers): iterable
    {
        if (! is_array($offers)) {
            return;
        }
        if (array_is_list($offers)) {
            foreach ($offers as $offer) {
                if (is_array($offer)) {
                    yield $offer;
                }
            }

            return;
        }

        yield $offers;
    }

    private function isProduct(array $node): bool
    {
        $type = $node['@type'] ?? null;

        return $type === 'Product' || is_array($type) && in_array('Product', $type, true);
    }

    private function pause(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
