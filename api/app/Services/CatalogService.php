<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CatalogService
{
    private const HIDDEN_NAME_PATTERNS = [
        '%dán kính%',
        '%Dán kính%',
        '%dán màn hình%',
        '%Dán màn hình%',
        '%cường lực%',
        '%Cường lực%',
        '%ốp lưng%',
        '%Ốp lưng%',
        '%phủ phím%',
        '%Phủ phím%',
        '%bộ vệ sinh laptop%',
        '%Bộ vệ sinh Laptop%',
        '%túi laptop%',
        '%Túi Laptop%',
        '%túi macbook%',
        '%Túi Macbook%',
        '%túi máy tính%',
        '%Túi máy tính%',
        '%đã kích hoạt%',
        '%Đã kích hoạt%',
        '%Đã Kích Hoạt%',
        '%Đã kích hoat%',
    ];

    private const PHONE_ACCESSORY_NAME_PATTERNS = [
        '%dán kính%',
        '%Dán kính%',
        '%cường lực%',
        '%Cường lực%',
        '%ốp lưng%',
        '%Ốp lưng%',
        '%bao da%',
        '%Bao da%',
        '%miếng dán%',
        '%Miếng dán%',
        '%applecare%',
        '%AppleCare%',
        '%túi chống nước%',
        '%Túi chống nước%',
        '%đế giữ điện thoại%',
        '%Đế giữ điện thoại%',
        '%giá đỡ điện thoại%',
        '%Giá đỡ điện thoại%',
        '%giá đỡ từ tính%',
        '%Giá đỡ từ tính%',
        '%đế sạc không dây%',
        '%Đế sạc không dây%',
        '%sạc không dây%',
        '%Sạc không dây%',
        '%ví iphone%',
        '%Ví iPhone%',
        '%ví đựng thẻ%',
        '%Ví đựng thẻ%',
        '%combo pin%',
        '%Combo pin%',
        '%vòng giữ điện thoại%',
        '%Vòng giữ điện thoại%',
        '%đồng hồ thông minh%',
        '%Đồng hồ thông minh%',
        '%bubble màn hình%',
        '%Bubble màn hình%',
        '%ống kính hasselblad%',
        '%Ống kính Hasselblad%',
        '%bàn phím cơ%',
        '%Bàn phím cơ%',
    ];

    private const SERVICE_NAME_PATTERNS = [
        '%thay %',
        '%Thay %',
        '%sửa chữa%',
        '%Sửa chữa%',
        '%nâng cấp%',
        '%Nâng cấp%',
        '%lắp đặt%',
        '%Lắp đặt%',
        '%cài đặt%',
        '%Cài đặt%',
        '%dịch vụ%',
        '%Dịch vụ%',
        '%bảo dưỡng%',
        '%Bảo dưỡng%',
    ];

    private const COMPONENT_NAME_PATTERNS = [
        '%ram laptop%',
        '%Ram Laptop%',
        '%RAM Laptop%',
        '%ram pc%',
        '%Ram PC%',
        '%RAM PC%',
        '%ssd laptop%',
        '%SSD Laptop%',
        '%ổ cứng%',
        '%Ổ cứng%',
        '%cpu rời%',
        '%CPU rời%',
        '%card đồ họa%',
        '%Card đồ họa%',
        '%bo mạch chủ%',
        '%Bo mạch chủ%',
        '%mainboard%',
        '%Mainboard%',
        '%nguồn máy tính%',
        '%Nguồn máy tính%',
        '%quạt tản nhiệt%',
        '%Quạt tản nhiệt%',
    ];

    public function catalog(array $filters = []): LengthAwarePaginator
    {
        return $this->withTrustSignals($this->publishedProducts($filters))
            ->with('images')
            ->orderBy(match ($filters['sort'] ?? 'featured') {
                'price_asc' => 'gia_ban',
                'price_desc' => 'gia_ban',
                'name' => 'ten_sp',
                'best_selling' => DB::raw($this->soldCountExpression()),
                'top_rated' => DB::raw($this->averageRatingExpression()),
                default => 'stt',
            }, in_array($filters['sort'] ?? '', ['price_desc', 'best_selling', 'top_rated'], true) ? 'desc' : 'asc')
            ->orderBy('ma_sp')
            ->paginate($filters['per_page'] ?? 12)
            ->withQueryString();
    }

    public function findPublished(string $slug): Product
    {
        return $this->withTrustSignals($this->publishedProducts())
            ->with('images')
            ->where(function (Builder $query) use ($slug): void {
                $query->where('slug', $slug)
                    ->orWhere('ma_sp', $slug);
            })
            ->firstOrFail();
    }

    public function categories(): Collection
    {
        $categories = $this->publishedProducts()
            ->whereNotNull('loai')
            ->where('loai', '!=', '')
            ->where(function (Builder $query): void {
                $this->whereNameMatchesNone($query, [...self::SERVICE_NAME_PATTERNS, ...self::COMPONENT_NAME_PATTERNS]);
            })
            ->select('loai')
            ->distinct()
            ->pluck('loai');

        if ($this->productsMatchingName(self::SERVICE_NAME_PATTERNS)
            ->where('trang_thai_hien_thi', 'published')
            ->where('gia_ban', '>', 0)
            ->where('so_luong', '>', 0)
            ->exists()) {
            $categories->push('Dịch vụ');
        }
        if ($this->productsMatchingName(self::COMPONENT_NAME_PATTERNS)
            ->where('trang_thai_hien_thi', 'published')
            ->where('gia_ban', '>', 0)
            ->where('so_luong', '>', 0)
            ->where(function (Builder $query): void {
                $this->whereNameMatchesNone($query, self::SERVICE_NAME_PATTERNS);
            })
            ->exists()) {
            $categories->push('Linh kiện');
        }

        return $categories->unique()->sort(function (string $left, string $right): int {
            $priority = ['Laptop' => 0, 'Điện thoại' => 1];

            return ($priority[$left] ?? 2) <=> ($priority[$right] ?? 2) ?: strnatcasecmp($left, $right);
        })->values();
    }

    public function filters(array $filters = []): array
    {
        $products = $this->publishedProducts($filters);
        $priceRange = (clone $products)
            ->selectRaw('MIN(gia_ban) as min_price, MAX(gia_ban) as max_price')
            ->first();
        $specificationFacets = [];

        foreach ($this->specificationDefinitions($filters['category'] ?? null) as $definition) {
            $jsonValue = $this->jsonSpecificationExpression($definition['source']);
            $specificationFacets[] = [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'options' => $this->groupedFacetOptions(
                    (clone $products)
                        ->whereRaw("{$jsonValue} IS NOT NULL AND {$jsonValue} <> ''")
                        ->selectRaw("{$jsonValue} as facet_value, COUNT(*) as facet_count")
                        ->groupByRaw($jsonValue)
                        ->get(),
                ),
            ];
        }

        return [
            'brands' => $this->groupedFacetOptions(
                (clone $products)
                    ->whereNotNull('thuong_hieu')
                    ->where('thuong_hieu', '!=', '')
                    ->selectRaw('thuong_hieu as facet_value, COUNT(*) as facet_count')
                    ->groupBy('thuong_hieu')
                    ->get(),
            ),
            'specifications' => $specificationFacets,
            'price' => [
                'min' => $priceRange->min_price === null ? null : (int) $priceRange->min_price,
                'max' => $priceRange->max_price === null ? null : (int) $priceRange->max_price,
            ],
        ];
    }

    public function brands(): Collection
    {
        return $this->publishedProducts()
            ->whereNotNull('thuong_hieu')
            ->where('thuong_hieu', '!=', '')
            ->distinct()
            ->orderBy('thuong_hieu')
            ->pluck('thuong_hieu');
    }

    public function chatbotSearch(array $filters = []): Collection
    {
        $products = $this->publishedProducts($filters);
        if (($filters['gaming'] ?? false) === true || ($filters['gaming'] ?? null) === '1' || ($filters['gaming'] ?? null) === 1) {
            $products->where(function (Builder $query): void {
                foreach (['gaming', 'ROG', 'Legion', 'Predator', 'TUF', 'Omen', 'Nitro', 'LOQ', 'Victus', 'AORUS', 'Katana'] as $modelLine) {
                    $query->orWhere('ten_sp', 'like', '%'.$modelLine.'%');
                }
                foreach (['RTX', 'GTX', 'GeForce', 'Radeon RX', 'Intel Arc'] as $graphicsLine) {
                    $query->orWhereRaw("JSON_EXTRACT(thong_so_ky_thuat, '$.graphics') LIKE ?", ['%'.$graphicsLine.'%']);
                }
            });
        }

        return $products
            ->orderBy('stt')
            ->limit(5)
            ->get()
            ->map(fn (Product $product) => $this->present($product));
    }

    public function present(Product $product): array
    {
        return [
            'id' => $product->ma_sp,
            'slug' => $product->slug ?: $product->ma_sp,
            'name' => $product->ten_sp,
            'category' => $this->publicCategoryName($product->ten_sp, $product->loai),
            'brand' => $product->thuong_hieu,
            'description' => $product->mo_ta,
            'specifications' => $product->thong_so_ky_thuat ?? [],
            'price' => (float) $product->gia_ban,
            'list_price' => $product->gia_niem_yet === null ? null : (float) $product->gia_niem_yet,
            'average_rating' => (float) ($product->average_rating ?? 0),
            'review_count' => (int) ($product->review_count ?? 0),
            'sold_count' => (int) ($product->sold_count ?? 0),
            'stock' => (int) $product->so_luong,
            'purchasable' => $product->trang_thai_hien_thi === 'published'
                && (int) $product->gia_ban > 0
                && (int) $product->so_luong > 0,
            'images' => $product->relationLoaded('images')
                ? $product->images->map(fn ($image) => $this->presentImage($image))->values()
                : [],
        ];
    }

    private function presentImage(object $image): array
    {
        $url = Str::startsWith($image->duong_dan_anh, ['http://', 'https://'])
            ? $image->duong_dan_anh
            : asset('storage/'.ltrim($image->duong_dan_anh, '/'));
        $responsive = [];

        if (! Str::startsWith($image->duong_dan_anh, ['http://', 'https://'])) {
            $sourcePath = ltrim($image->duong_dan_anh, '/');
            $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
            $basePath = substr($sourcePath, 0, -strlen($extension) - 1);
            foreach ([320, 480, 960] as $width) {
                $variantPath = "{$basePath}-{$width}.webp";
                if (Storage::disk('public')->exists($variantPath)) {
                    $responsive[] = asset('storage/'.$variantPath).' '.$width.'w';
                }
            }
        }

        return [
            'url' => $url,
            'alt' => $image->alt_text,
            'srcset' => $responsive === [] ? null : implode(', ', $responsive),
        ];
    }

    private function withTrustSignals(Builder $query): Builder
    {
        $query->addSelect('san_pham.*');

        if (Schema::hasTable('danh_gia')
            && Schema::hasColumn('danh_gia', 'ma_sp')
            && Schema::hasColumn('danh_gia', 'rating')) {
            $query
                ->selectSub(
                    DB::table('danh_gia')
                        ->selectRaw('COALESCE(AVG(rating), 0)')
                        ->whereColumn('danh_gia.ma_sp', 'san_pham.ma_sp'),
                    'average_rating',
                )
                ->selectSub(
                    DB::table('danh_gia')
                        ->selectRaw('COUNT(*)')
                        ->whereColumn('danh_gia.ma_sp', 'san_pham.ma_sp'),
                    'review_count',
                );
        }

        if (Schema::hasTable('chi_tiet_don_hang')
            && Schema::hasColumn('chi_tiet_don_hang', 'ma_sp')
            && Schema::hasColumn('chi_tiet_don_hang', 'ma_dh')
            && Schema::hasColumn('chi_tiet_don_hang', 'so_luong')
            && Schema::hasTable('don_hang')
            && Schema::hasColumn('don_hang', 'ma_dh')
            && Schema::hasColumn('don_hang', 'trang_thai')) {
            $query->selectSub(
                DB::table('chi_tiet_don_hang as order_items')
                    ->join('don_hang as orders', 'orders.ma_dh', '=', 'order_items.ma_dh')
                    ->selectRaw('COALESCE(SUM(order_items.so_luong), 0)')
                    ->whereColumn('order_items.ma_sp', 'san_pham.ma_sp')
                    ->where('orders.trang_thai', 'Da Giao'),
                'sold_count',
            );
        }

        return $query;
    }

    private function publishedProducts(array $filters = []): Builder
    {
        return $this->catalogProducts($filters)->availableForSale();
    }

    private function catalogProducts(array $filters = []): Builder
    {
        $query = $this->visibleCatalogProducts();

        if (isset($filters['category'])) {
            $category = $filters['category'];
            if ($category === 'Dịch vụ') {
                $this->whereNameMatchesAny($query, self::SERVICE_NAME_PATTERNS);
            } elseif ($category === 'Linh kiện') {
                $this->whereNameMatchesAny($query, self::COMPONENT_NAME_PATTERNS);
                $this->whereNameMatchesNone($query, self::SERVICE_NAME_PATTERNS);
            } else {
                $query->where('loai', $category);
                $this->whereNameMatchesNone($query, [...self::SERVICE_NAME_PATTERNS, ...self::COMPONENT_NAME_PATTERNS]);
            }
        }

        if (isset($filters['q'])) {
            $terms = preg_split('/\s+/u', trim($filters['q']), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($terms as $word) {
                $term = '%'.addcslashes($word, '\\%_').'%';
                $query->where(function (Builder $search) use ($term): void {
                    $search->where('ten_sp', 'like', $term)
                        ->orWhere('thuong_hieu', 'like', $term)
                        ->orWhere('loai', 'like', $term);
                });
            }
        }

        $query
            ->when(isset($filters['exclude_category']), fn (Builder $query) => $query->where('loai', '!=', $filters['exclude_category']))
            ->when(isset($filters['brand']), fn (Builder $query) => $query->where('thuong_hieu', $filters['brand']))
            ->when(isset($filters['min_price']), fn (Builder $query) => $query->where('gia_ban', '>=', $filters['min_price']))
            ->when(isset($filters['max_price']), fn (Builder $query) => $query->where('gia_ban', '<=', $filters['max_price']));

        if (($filters['on_sale'] ?? false) === true || ($filters['on_sale'] ?? null) === '1' || ($filters['on_sale'] ?? null) === 1) {
            $query->whereNotNull('gia_niem_yet')
                ->whereColumn('gia_niem_yet', '>', 'gia_ban');
        }

        if (isset($filters['min_rating'])) {
            $ratingColumn = $this->reviewRatingColumn();
            if ($ratingColumn === null) {
                $query->whereRaw('1 = 0');
            } else {
                $matchingReviews = DB::table('danh_gia')
                    ->select('ma_sp')
                    ->groupBy('ma_sp')
                    ->havingRaw("AVG({$ratingColumn}) >= CAST(? AS DECIMAL(5, 2))", [(string) $filters['min_rating']]);
                $query->whereIn('san_pham.ma_sp', $matchingReviews);
            }
        }

        foreach ([
            'cpu' => 'cpu',
            'ram' => 'ram',
            'rom' => 'storage',
            'gpu' => 'graphics',
            'os' => 'operating_system',
            'display' => 'display',
            'battery' => 'battery',
        ] as $filter => $specification) {
            if (isset($filters[$filter])) {
                $value = '%'.addcslashes($filters[$filter], '\\%_').'%';
                $query->whereRaw("JSON_EXTRACT(thong_so_ky_thuat, '$.{$specification}') LIKE ?", [$value]);
            }
        }

        return $query;
    }

    private function reviewRatingColumn(): ?string
    {
        if (! Schema::hasTable('danh_gia')) {
            return null;
        }
        if (Schema::hasColumn('danh_gia', 'rating')) {
            return 'rating';
        }
        if (Schema::hasColumn('danh_gia', 'so_sao')) {
            return 'so_sao';
        }

        return null;
    }

    private function averageRatingExpression(): string
    {
        $ratingColumn = $this->reviewRatingColumn();
        if ($ratingColumn === null) {
            return '0';
        }

        return "(SELECT COALESCE(AVG({$ratingColumn}), 0) FROM danh_gia WHERE danh_gia.ma_sp = san_pham.ma_sp)";
    }

    private function soldCountExpression(): string
    {
        if (! Schema::hasTable('chi_tiet_don_hang')
            || ! Schema::hasColumn('chi_tiet_don_hang', 'ma_sp')
            || ! Schema::hasColumn('chi_tiet_don_hang', 'ma_dh')
            || ! Schema::hasColumn('chi_tiet_don_hang', 'so_luong')
            || ! Schema::hasTable('don_hang')
            || ! Schema::hasColumn('don_hang', 'ma_dh')
            || ! Schema::hasColumn('don_hang', 'trang_thai')) {
            return '0';
        }

        return "(SELECT COALESCE(SUM(order_items.so_luong), 0) FROM chi_tiet_don_hang as order_items JOIN don_hang as orders ON orders.ma_dh = order_items.ma_dh WHERE order_items.ma_sp = san_pham.ma_sp AND orders.trang_thai = 'Da Giao')";
    }

    private function visibleCatalogProducts(): Builder
    {
        $query = Product::query()
            ->whereNotNull('ten_sp')
            ->where('ten_sp', '!=', '')
            ->where('loai', '!=', 'Khác')
            ->when(Schema::hasColumn('san_pham', 'deleted_at'), fn (Builder $query) => $query->whereNull('deleted_at'))
            ->when(
                Schema::hasColumn('san_pham', 'la_hang_cu'),
                fn (Builder $query) => $query->where('la_hang_cu', false),
                fn (Builder $query) => $query->where('ten_sp', 'not like', '% - Cũ%'),
            );
        $this->whereNameMatchesNone($query, self::HIDDEN_NAME_PATTERNS);
        $query->where(function (Builder $query): void {
            $query->where('loai', '!=', 'Điện thoại')
                ->orWhere(function (Builder $phoneProducts): void {
                    $this->whereNameMatchesNone($phoneProducts, self::PHONE_ACCESSORY_NAME_PATTERNS);
                });
        });

        return $query;
    }

    private function productsMatchingName(array $patterns): Builder
    {
        $query = $this->visibleCatalogProducts();
        $this->whereNameMatchesAny($query, $patterns);

        return $query;
    }

    private function whereNameMatchesAny(Builder $query, array $patterns): void
    {
        $query->where(function (Builder $matches) use ($patterns): void {
            foreach ($patterns as $pattern) {
                $matches->orWhere('ten_sp', 'like', $pattern);
            }
        });
    }

    private function whereNameMatchesNone(Builder $query, array $patterns): void
    {
        $query->where(function (Builder $doesNotMatch) use ($patterns): void {
            foreach ($patterns as $pattern) {
                $doesNotMatch->where('ten_sp', 'not like', $pattern);
            }
        });
    }

    private function publicCategoryName(?string $name, ?string $category): ?string
    {
        $normalized = Str::ascii(Str::lower($name ?? ''));
        if (preg_match('/\b(thay|sua chua|nang cap|lap dat|cai dat|dich vu|bao duong)\b/', $normalized) === 1) {
            return 'Dịch vụ';
        }
        if (preg_match('/\b(ram (laptop|pc)|ssd laptop|o cung|cpu roi|card do hoa|bo mach chu|mainboard|nguon may tinh|quat tan nhiet)\b/', $normalized) === 1) {
            return 'Linh kiện';
        }

        return $category;
    }

    private function specificationDefinitions(?string $category): array
    {
        if ($category === null || trim($category) === '') {
            return [];
        }

        $normalized = Str::ascii(Str::lower($category));
        if (preg_match('/laptop|desktop|may tinh de ban|may tinh bo|(?:^|\\W)pc(?:$|\\W)/', $normalized)) {
            return [
                ['key' => 'cpu', 'label' => 'CPU', 'source' => 'cpu'],
                ['key' => 'ram', 'label' => 'RAM', 'source' => 'ram'],
                ['key' => 'rom', 'label' => 'Ổ cứng', 'source' => 'storage'],
                ['key' => 'gpu', 'label' => 'Card màn hình', 'source' => 'graphics'],
                ['key' => 'os', 'label' => 'Hệ điều hành', 'source' => 'operating_system'],
            ];
        }

        if (preg_match('/dien thoai|smartphone|may tinh bang|tablet/', $normalized)) {
            return [
                ['key' => 'cpu', 'label' => 'Chip xử lý', 'source' => 'cpu'],
                ['key' => 'ram', 'label' => 'RAM', 'source' => 'ram'],
                ['key' => 'rom', 'label' => 'Dung lượng lưu trữ', 'source' => 'storage'],
                ['key' => 'display', 'label' => 'Màn hình', 'source' => 'display'],
                ['key' => 'battery', 'label' => 'Pin', 'source' => 'battery'],
                ['key' => 'os', 'label' => 'Hệ điều hành', 'source' => 'operating_system'],
            ];
        }

        if (preg_match('/man hinh|tivi|television/', $normalized)) {
            return [
                ['key' => 'display', 'label' => 'Thông số màn hình', 'source' => 'display'],
            ];
        }

        if (preg_match('/dong ho|smartwatch|watch/', $normalized)) {
            return [
                ['key' => 'display', 'label' => 'Màn hình', 'source' => 'display'],
                ['key' => 'battery', 'label' => 'Pin', 'source' => 'battery'],
                ['key' => 'os', 'label' => 'Hệ điều hành', 'source' => 'operating_system'],
            ];
        }

        return [];
    }

    private function groupedFacetOptions(iterable $groups): array
    {
        $counts = [];
        foreach ($groups as $group) {
            $value = $group->facet_value;
            if (! is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '') {
                $counts[$value] = ($counts[$value] ?? 0) + (int) $group->facet_count;
            }
        }

        $options = [];
        foreach ($counts as $value => $count) {
            $options[] = ['value' => $value, 'count' => $count];
        }
        usort($options, fn (array $left, array $right) => ($right['count'] <=> $left['count'])
            ?: strnatcasecmp($left['value'], $right['value']));

        return $options;
    }

    private function jsonSpecificationExpression(string $source): string
    {
        $path = '$.'.$source;

        return match (Schema::getConnection()->getDriverName()) {
            'sqlite' => "json_extract(thong_so_ky_thuat, '{$path}')",
            default => "JSON_UNQUOTE(JSON_EXTRACT(thong_so_ky_thuat, '{$path}'))",
        };
    }
}
