<?php

namespace Tests\Feature;

use App\Services\CatalogService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('san_pham_hinh_anh');
        Schema::dropIfExists('san_pham');
        $this->createLegacyProductTable();
    }

    public function test_public_catalog_shows_only_published_priced_and_in_stock_products(): void
    {
        $this->insertProduct('LAPTOP01', 'Laptop A', 'Laptop', 'Brand A', 15000000, 2, 'laptop-a');
        $this->insertProduct('LAPTOP02', 'Laptop B', 'Laptop', 'Brand B', 0, 4, 'laptop-b');
        $this->insertProduct('LAPTOP03', 'Laptop C', 'Laptop', 'Brand C', 12000000, 3, 'laptop-c', 'draft');
        $this->insertProduct('LAPTOP04', 'Laptop D', 'Laptop', 'Brand D', 18000000, 0, 'laptop-d');

        $this->getJson('/api/v1/products?q=Laptop')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 'LAPTOP01')
            ->assertJsonPath('data.0.purchasable', true);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('data.0', 'Laptop')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");

        foreach (['LAPTOP02', 'laptop-c', 'laptop-d'] as $unavailableProduct) {
            $this->getJson("/api/v1/products/{$unavailableProduct}")->assertNotFound();
        }
    }

    public function test_chatbot_gaming_search_returns_gaming_lines_or_discrete_graphics_only(): void
    {
        $this->insertProduct('LAPTOP01', 'Laptop A', 'Laptop', 'Brand A', 15000000, 2, 'laptop-a');
        $this->insertProduct('LAPTOP02', 'Laptop Acer Nitro Gaming', 'Laptop', 'Acer', 20000000, 2, 'laptop-nitro');
        $this->insertProduct('LAPTOP03', 'Laptop Work B', 'Laptop', 'Brand B', 15000000, 2, 'laptop-work');
        DB::table('san_pham')->where('ma_sp', 'LAPTOP01')->update([
            'thong_so_ky_thuat' => json_encode(['graphics' => 'NVIDIA GeForce RTX 4060'], JSON_THROW_ON_ERROR),
        ]);
        DB::table('san_pham')->where('ma_sp', 'LAPTOP03')->update([
            'thong_so_ky_thuat' => json_encode(['graphics' => 'Integrated Intel UHD'], JSON_THROW_ON_ERROR),
        ]);

        $results = app(CatalogService::class)->chatbotSearch([
            'q' => 'Laptop',
            'category' => 'Laptop',
            'gaming' => true,
        ]);

        $this->assertSame(['LAPTOP01', 'LAPTOP02'], $results->pluck('id')->all());
    }

    public function test_public_product_cards_include_real_review_and_delivered_sales_totals(): void
    {
        $this->insertProduct('LAPTOP01', 'Laptop A', 'Laptop', 'Brand A', 15000000, 2, 'laptop-a');
        if (! Schema::hasTable('danh_gia')) {
            Schema::create('danh_gia', function (Blueprint $table): void {
                $table->id();
                $table->string('ma_kh', 20);
                $table->char('ma_sp', 10);
                $table->unsignedTinyInteger('rating');
                $table->text('content');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('don_hang')) {
            Schema::create('don_hang', function (Blueprint $table): void {
                $table->char('ma_dh', 10)->primary();
                $table->string('ma_kh', 20)->nullable();
                $table->date('ngay_dat');
                $table->decimal('tong_tien', 12, 0);
                $table->string('trang_thai', 50);
            });
        }
        if (! Schema::hasTable('chi_tiet_don_hang')) {
            Schema::create('chi_tiet_don_hang', function (Blueprint $table): void {
                $table->char('ma_dh', 10);
                $table->char('ma_sp', 10);
                $table->unsignedInteger('so_luong');
                $table->decimal('don_gia', 10, 0);
                $table->primary(['ma_dh', 'ma_sp']);
            });
        }
        DB::table('danh_gia')->insert([
            ['ma_kh' => 'CUSTOMER01', 'ma_sp' => 'LAPTOP01', 'rating' => 5, 'content' => 'Tốt', 'created_at' => now(), 'updated_at' => now()],
            ['ma_kh' => 'CUSTOMER02', 'ma_sp' => 'LAPTOP01', 'rating' => 4, 'content' => 'Ổn', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('don_hang')->insert([
            ['ma_dh' => 'ORDER0001', 'ma_kh' => null, 'ngay_dat' => now()->toDateString(), 'tong_tien' => 15000000, 'trang_thai' => 'Da Giao'],
            ['ma_dh' => 'ORDER0002', 'ma_kh' => null, 'ngay_dat' => now()->toDateString(), 'tong_tien' => 15000000, 'trang_thai' => 'huy'],
        ]);
        DB::table('chi_tiet_don_hang')->insert([
            ['ma_dh' => 'ORDER0001', 'ma_sp' => 'LAPTOP01', 'so_luong' => 3, 'don_gia' => 15000000],
            ['ma_dh' => 'ORDER0002', 'ma_sp' => 'LAPTOP01', 'so_luong' => 7, 'don_gia' => 15000000],
        ]);

        $this->getJson('/api/v1/products?category=Laptop')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'LAPTOP01')
            ->assertJsonPath('data.0.name', 'Laptop A')
            ->assertJsonPath('data.0.average_rating', 4.5)
            ->assertJsonPath('data.0.review_count', 2)
            ->assertJsonPath('data.0.sold_count', 3);
    }

    public function test_product_filters_and_validation_are_applied(): void
    {
        $this->insertProduct('LAPTOP01', 'Laptop A', 'Laptop', 'Brand A', 15000000, 2, 'laptop-a');
        $this->insertProduct('PHONE001', 'Phone B', 'Phone', 'Brand A', 10000000, 2, 'phone-b');

        $this->getJson('/api/v1/products?category=Laptop&min_price=12000000&sort=price_desc')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 'LAPTOP01');

        $this->getJson('/api/v1/products?exclude_category=Laptop')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 'PHONE001');

        $this->getJson('/api/v1/products?per_page=100')
            ->assertUnprocessable();
    }

    public function test_public_catalog_filters_discounts_and_ratings_and_sorts_trust_signals(): void
    {
        $this->insertProduct('LAPTOP01', 'Laptop A', 'Laptop', 'Brand A', 15000000, 2, 'laptop-a');
        $this->insertProduct('LAPTOP02', 'Laptop B', 'Laptop', 'Brand B', 12000000, 2, 'laptop-b');
        DB::table('san_pham')->where('ma_sp', 'LAPTOP01')->update(['gia_niem_yet' => 18000000]);

        Schema::create('danh_gia', function (Blueprint $table): void {
            $table->id();
            $table->string('ma_kh', 20);
            $table->char('ma_sp', 10);
            $table->unsignedTinyInteger('rating');
            $table->text('content');
            $table->timestamps();
        });
        Schema::create('don_hang', function (Blueprint $table): void {
            $table->char('ma_dh', 10)->primary();
            $table->string('ma_kh', 20)->nullable();
            $table->date('ngay_dat');
            $table->decimal('tong_tien', 12, 0);
            $table->string('trang_thai', 50);
        });
        Schema::create('chi_tiet_don_hang', function (Blueprint $table): void {
            $table->char('ma_dh', 10);
            $table->char('ma_sp', 10);
            $table->unsignedInteger('so_luong');
            $table->decimal('don_gia', 10, 0);
            $table->primary(['ma_dh', 'ma_sp']);
        });
        DB::table('danh_gia')->insert([
            ['ma_kh' => 'CUSTOMER01', 'ma_sp' => 'LAPTOP01', 'rating' => 5, 'content' => 'Tốt', 'created_at' => now(), 'updated_at' => now()],
            ['ma_kh' => 'CUSTOMER02', 'ma_sp' => 'LAPTOP02', 'rating' => 3, 'content' => 'Ổn', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('don_hang')->insert([
            ['ma_dh' => 'ORDER0001', 'ma_kh' => null, 'ngay_dat' => now()->toDateString(), 'tong_tien' => 15000000, 'trang_thai' => 'Da Giao'],
        ]);
        DB::table('chi_tiet_don_hang')->insert([
            ['ma_dh' => 'ORDER0001', 'ma_sp' => 'LAPTOP01', 'so_luong' => 2, 'don_gia' => 15000000],
            ['ma_dh' => 'ORDER0001', 'ma_sp' => 'LAPTOP02', 'so_luong' => 8, 'don_gia' => 12000000],
        ]);

        $ratingResponse = $this->getJson('/api/v1/products?min_rating=4');
        $ratingResponse
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 'LAPTOP01');
        $this->getJson('/api/v1/products?min_rating=4.5')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 'LAPTOP01');
        $this->getJson('/api/v1/products?on_sale=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 'LAPTOP01');
        $this->getJson('/api/v1/products?sort=best_selling')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'LAPTOP02');
        $this->getJson('/api/v1/products?sort=top_rated')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'LAPTOP01');
        $this->getJson('/api/v1/products?min_rating=6')->assertUnprocessable();
    }

    public function test_product_presenter_adds_only_existing_responsive_image_variants(): void
    {
        Storage::fake('public');
        $this->insertProduct('LAPTOP01', 'Laptop A', 'Laptop', 'Brand A', 15000000, 2, 'laptop-a');
        Storage::disk('public')->put('catalog/LAPTOP01/photo.jpg', 'original');
        Storage::disk('public')->put('catalog/LAPTOP01/photo-320.webp', '320');
        Storage::disk('public')->put('catalog/LAPTOP01/photo-480.webp', '480');
        DB::table('san_pham_hinh_anh')->insert([
            'ma_sp' => 'LAPTOP01',
            'duong_dan_anh' => 'catalog/LAPTOP01/photo.jpg',
            'alt_text' => 'Laptop A',
            'thu_tu' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/products/laptop-a')
            ->assertOk()
            ->assertJsonPath('data.images.0.srcset', fn (string $srcset): bool => str_contains($srcset, 'photo-320.webp 320w')
                && str_contains($srcset, 'photo-480.webp 480w')
                && ! str_contains($srcset, 'photo-960.webp'));
    }

    public function test_catalog_filters_by_normalized_hardware_and_operating_system_specs(): void
    {
        $this->insertProduct('LAPTOP01', 'Laptop A', 'Laptop', 'Brand A', 15000000, 2, 'laptop-a');
        $this->insertProduct('LAPTOP02', 'Laptop B', 'Laptop', 'Brand B', 25000000, 2, 'laptop-b');
        DB::table('san_pham')->where('ma_sp', 'LAPTOP01')->update([
            'thong_so_ky_thuat' => json_encode([
                'cpu' => 'Intel Core i7',
                'ram' => '16 GB DDR5',
                'storage' => '512 GB SSD',
                'graphics' => 'NVIDIA RTX 4060',
                'operating_system' => 'Windows 11',
            ], JSON_THROW_ON_ERROR),
        ]);
        DB::table('san_pham')->where('ma_sp', 'LAPTOP02')->update([
            'thong_so_ky_thuat' => json_encode([
                'cpu' => 'Apple M3',
                'ram' => '8 GB',
                'storage' => '256 GB SSD',
                'graphics' => 'Tích hợp',
                'operating_system' => 'macOS',
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->getJson('/api/v1/products?cpu=i7&ram=DDR5&rom=512&gpu=RTX&os=Windows&max_price=20000000')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 'LAPTOP01');

        $this->getJson('/api/v1/products?os=Linux')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/products?cpu[]=bad')
            ->assertUnprocessable();
    }

    public function test_catalog_filter_facets_include_all_products_and_relevant_to_category(): void
    {
        $this->insertProduct('LAPTOP01', 'Laptop A', 'Laptop', 'Brand A', 15000000, 2, 'laptop-a');
        $this->insertProduct('LAPTOP02', 'Laptop B', 'Laptop', 'Brand A', 25000000, 1, 'laptop-b');
        $this->insertProduct('PHONE001', 'Phone A', 'Điện thoại', 'Brand B', 10000000, 3, 'phone-a');
        $this->insertProduct('LAPTOP03', 'Laptop Draft', 'Laptop', 'Brand C', 12000000, 0, 'laptop-draft', 'draft');

        DB::table('san_pham')->where('ma_sp', 'LAPTOP01')->update([
            'thong_so_ky_thuat' => json_encode([
                'cpu' => 'Intel Core i7',
                'ram' => '16 GB DDR5',
                'storage' => '512 GB SSD',
                'graphics' => 'NVIDIA RTX 4060',
                'operating_system' => 'Windows 11',
            ], JSON_THROW_ON_ERROR),
        ]);
        DB::table('san_pham')->where('ma_sp', 'LAPTOP02')->update([
            'thong_so_ky_thuat' => json_encode([
                'cpu' => 'Intel Core i5',
                'ram' => '8 GB DDR5',
                'storage' => '256 GB SSD',
                'graphics' => 'Integrated graphics',
                'operating_system' => 'Windows 11',
            ], JSON_THROW_ON_ERROR),
        ]);
        DB::table('san_pham')->where('ma_sp', 'PHONE001')->update([
            'thong_so_ky_thuat' => json_encode([
                'cpu' => 'Snapdragon 8',
                'ram' => '12 GB',
                'storage' => '256 GB',
                'display' => '6.7 inch AMOLED',
                'battery' => '5000 mAh',
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('data.0', 'Laptop');

        $this->getJson('/api/v1/filters?category=Laptop')
            ->assertOk()
            ->assertJsonPath('data.brands.0.value', 'Brand A')
            ->assertJsonPath('data.brands.0.count', 2)
            ->assertJsonPath('data.specifications.0.key', 'cpu')
            ->assertJsonPath('data.specifications.3.key', 'gpu')
            ->assertJsonPath('data.specifications.4.key', 'os')
            ->assertJsonPath('data.price.min', 15000000)
            ->assertJsonPath('data.price.max', 25000000);

        $this->getJson('/api/v1/filters?category=Điện thoại')
            ->assertOk()
            ->assertJsonCount(6, 'data.specifications')
            ->assertJsonPath('data.specifications.0.key', 'cpu')
            ->assertJsonPath('data.specifications.3.key', 'display')
            ->assertJsonPath('data.specifications.4.key', 'battery');

        $this->getJson('/api/v1/products?brand=Brand%20B&display=6.7&battery=5000')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 'PHONE001');
    }

    public function test_catalog_composite_indexes_migration_is_repeatable(): void
    {
        $migration = require database_path('migrations/2026_10_23_000007_add_catalog_composite_indexes.php');

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasIndex('san_pham', 'san_pham_catalog_category_order_idx'));
        $this->assertTrue(Schema::hasIndex('san_pham', 'san_pham_catalog_category_brand_order_idx'));
    }

    public function test_product_detail_includes_public_image_data(): void
    {
        $this->insertProduct('LAPTOP01', 'Laptop A', 'Laptop', 'Brand A', 15000000, 2, 'laptop-a');
        DB::table('san_pham_hinh_anh')->insert([
            'ma_sp' => 'LAPTOP01',
            'duong_dan_anh' => 'laptop-a.jpg',
            'alt_text' => 'Laptop A',
            'thu_tu' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/products/laptop-a')
            ->assertOk()
            ->assertJsonPath('data.id', 'LAPTOP01')
            ->assertJsonPath('data.images.0.alt', 'Laptop A')
            ->assertJsonPath('data.images.0.url', asset('storage/laptop-a.jpg'));
    }

    public function test_product_detail_hides_a_draft_or_unpriced_product_by_its_id(): void
    {
        $this->insertProduct('LAPTOP01', 'Laptop Draft', 'Laptop', 'Brand A', 0, 0, 'laptop-draft', 'draft');

        $this->getJson('/api/v1/products/LAPTOP01')->assertNotFound();
    }

    public function test_used_products_are_hidden_from_public_catalog_search_categories_and_details(): void
    {
        $this->insertProduct('LAPTOP01', 'Laptop Gaming Acer Nitro - Cũ Trầy Xước', 'Laptop', 'Acer', 15000000, 2, 'laptop-cu');
        $this->insertProduct('LAPTOP02', 'Laptop Gaming Acer Nitro Mới', 'Laptop', 'Acer', 18000000, 2, 'laptop-moi');

        $this->getJson('/api/v1/products?category=Laptop')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 'LAPTOP02');

        $this->getJson('/api/v1/products?q=Trầy%20Xước')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/v1/products/laptop-cu')->assertNotFound();

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('data', ['Laptop']);
    }

    public function test_phone_accessories_and_the_other_category_are_hidden_from_the_storefront(): void
    {
        $this->insertProduct('LAPTOP01', 'Laptop A', 'Laptop', 'Brand A', 15000000, 2, 'laptop-a');
        $this->insertProduct('PHONE001', 'Điện thoại mẫu', 'Điện thoại', 'Brand B', 10000000, 2, 'phone-a');
        $this->insertProduct('PHONE002', 'Dán kính cường lực iPhone', 'Điện thoại', 'Brand B', 100000, 5, 'phone-screen-protector');
        $this->insertProduct('PHONE003', 'Ốp lưng iPhone', 'Điện thoại', 'Brand B', 200000, 5, 'phone-case');
        $this->insertProduct('PHONE004', 'Giá đỡ điện thoại trên ô tô', 'Điện thoại', 'Brand B', 300000, 5, 'phone-holder');
        $this->insertProduct('KEYB001', 'Bàn phím cơ không dây Akko', 'Chuột / Bàn phím', 'Akko', 1000000, 2, 'mechanical-keyboard');
        $this->insertProduct('OTHER001', 'Sản phẩm chưa phân loại', 'Khác', 'Brand C', 1000000, 2, 'uncategorized');

        $this->getJson('/api/v1/products?category=Điện thoại')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 'PHONE001');
        $this->getJson('/api/v1/products?q=Apple')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/products/phone-screen-protector')->assertNotFound();
        $this->getJson('/api/v1/products?category=Khác')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/products/uncategorized')->assertNotFound();
        $this->getJson('/api/v1/products?category=Chuột%20%2F%20Bàn%20phím')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 'KEYB001');

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('data', ['Laptop', 'Điện thoại', 'Chuột / Bàn phím']);
    }

    public function test_laptop_accessories_and_activated_products_are_hidden_and_services_and_parts_are_recategorized(): void
    {
        $this->insertProduct('LAPTOP01', 'Laptop Acer Nitro mới', 'Laptop', 'Acer', 18000000, 2, 'laptop-moi');
        $this->insertProduct('LAPTOP02', 'Dán kính cường lực màn hình Macbook Air', 'Laptop', 'JRC', 100000, 2, 'macbook-screen-protector');
        $this->insertProduct('LAPTOP03', 'Laptop Acer Nitro - Đã Kích Hoạt', 'Laptop', 'Acer', 15000000, 2, 'laptop-activated');
        $this->insertProduct('LAPTOP04', 'Ốp lưng Macbook Air JRC Transparent', 'Laptop', 'JRC', 200000, 2, 'macbook-case');
        $this->insertProduct('LAPTOP05', 'Phủ phím JRC Macbook Air', 'Laptop', 'JRC', 100000, 2, 'macbook-keyboard-cover');
        $this->insertProduct('LAPTOP06', 'Bộ vệ sinh Laptop JRC 5in1', 'Laptop', 'JRC', 150000, 2, 'laptop-cleaning-kit');
        $this->insertProduct('LAPTOP07', 'Túi Laptop Sakos Filox 13 inch', 'Laptop', 'Sakos', 500000, 2, 'laptop-bag');
        $this->insertProduct('SERVICE01', 'Thay RAM laptop Corsair DDR3L 8GB Bus 1600', 'Laptop', 'Corsair', 100000, 2, 'laptop-ram-service');
        $this->insertProduct('PART00001', 'Ram Laptop Adata DDR5 5600MHz 8GB', 'Laptop', 'Adata', 500000, 2, 'laptop-ram-part');

        $this->getJson('/api/v1/products?category=Laptop')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 'LAPTOP01');
        $this->getJson('/api/v1/products?category=Dịch%20vụ')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 'SERVICE01')
            ->assertJsonPath('data.0.category', 'Dịch vụ');
        $this->getJson('/api/v1/products?category=Linh%20kiện')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 'PART00001')
            ->assertJsonPath('data.0.category', 'Linh kiện');

        foreach (['macbook-screen-protector', 'laptop-activated', 'macbook-case', 'macbook-keyboard-cover', 'laptop-cleaning-kit', 'laptop-bag'] as $slug) {
            $this->getJson("/api/v1/products/{$slug}")->assertNotFound();
        }
        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('data.0', 'Laptop')
            ->assertJsonPath('data.1', 'Dịch vụ')
            ->assertJsonPath('data.2', 'Linh kiện');
    }

    public function test_used_product_migration_marks_existing_used_items_for_hiding(): void
    {
        $this->insertProduct('LAPTOP01', 'Laptop Gaming Acer Nitro - Cũ Trầy Xước', 'Laptop', 'Acer', 15000000, 2, 'laptop-cu');
        $this->insertProduct('LAPTOP02', 'Laptop Gaming Acer Nitro Mới', 'Laptop', 'Acer', 18000000, 2, 'laptop-moi');

        $migration = require database_path('migrations/2026_10_23_000003_add_used_product_flag.php');
        $migration->up();

        $this->assertDatabaseHas('san_pham', ['ma_sp' => 'LAPTOP01', 'la_hang_cu' => true]);
        $this->assertDatabaseHas('san_pham', ['ma_sp' => 'LAPTOP02', 'la_hang_cu' => false]);
        $this->getJson('/api/v1/products?category=Laptop')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 'LAPTOP02');
    }

    public function test_product_column_migration_widens_fields_and_normalizes_null_stock(): void
    {
        $this->insertProduct('LAPTOP01', 'Laptop A', 'Laptop', 'Brand A', 15000000, 2, 'laptop-a');
        DB::table('san_pham')->where('ma_sp', 'LAPTOP01')->update(['so_luong' => null]);

        $migration = require database_path('migrations/2026_10_23_000004_fix_product_column_constraints.php');
        $migration->up();

        $this->assertSame(0, (int) DB::table('san_pham')->where('ma_sp', 'LAPTOP01')->value('so_luong'));
        $this->assertSame('text', Schema::getColumnType('san_pham', 'mo_ta'));

        $longCategory = str_repeat('a', 80);
        $longDescription = str_repeat('b', 1000);
        DB::table('san_pham')->where('ma_sp', 'LAPTOP01')->update([
            'loai' => $longCategory,
            'mo_ta' => $longDescription,
        ]);

        $product = DB::table('san_pham')->where('ma_sp', 'LAPTOP01')->first();
        $this->assertSame($longCategory, $product->loai);
        $this->assertSame($longDescription, $product->mo_ta);
        $quantityColumn = collect(Schema::getColumns('san_pham'))
            ->firstWhere('name', 'so_luong');
        $this->assertFalse($quantityColumn['nullable']);
        $this->assertSame('0', trim((string) $quantityColumn['default'], "'"));
    }

    public function test_storefront_origin_is_allowed_to_call_the_catalog_api(): void
    {
        $this->call('OPTIONS', '/api/v1/products', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function test_internal_chatbot_search_requires_a_valid_service_token(): void
    {
        config(['services.chatbot.token' => 'test-service-token']);
        $this->insertProduct('LAPTOP01', 'Laptop A', 'Laptop', 'Brand A', 15000000, 2, 'laptop-a');
        $this->insertProduct('LAPTOP02', 'Laptop B', 'Laptop', 'Brand B', 25000000, 2, 'laptop-b');

        $this->getJson('/api/v1/internal/chatbot/products?q=Laptop')
            ->assertUnauthorized();

        $this->withToken('test-service-token')
            ->getJson('/api/v1/internal/chatbot/products?q=Laptop')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'LAPTOP01');

        $this->withToken('test-service-token')
            ->getJson('/api/v1/internal/chatbot/products?q=Laptop&category=Laptop&max_price=20000000')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'LAPTOP01')
            ->assertJsonCount(1, 'data');
    }

    public function test_catalog_migration_extends_the_imported_schema_and_keeps_existing_products(): void
    {
        Schema::dropIfExists('san_pham_hinh_anh');
        Schema::dropIfExists('san_pham');
        Schema::create('san_pham', function (Blueprint $table) {
            $table->char('ma_sp', 10)->primary();
            $table->string('ten_sp')->nullable();
            $table->string('loai', 20)->nullable();
            $table->string('thuong_hieu', 100)->nullable();
            $table->string('mo_ta')->nullable();
            $table->decimal('gia_ban', 10, 0)->nullable();
            $table->integer('so_luong')->nullable();
            $table->integer('stt');
        });
        DB::table('san_pham')->insert([
            'ma_sp' => 'LAPTOP01',
            'ten_sp' => 'Laptop A',
            'loai' => 'Laptop',
            'thuong_hieu' => 'Brand A',
            'gia_ban' => 15000000,
            'so_luong' => 2,
            'stt' => 0,
        ]);
        Schema::create('san_pham_hinh_anh', function (Blueprint $table): void {
            $table->bigIncrements('id_hinh_anh');
            $table->char('ma_sp', 10);
            $table->string('duong_dan_anh', 2048);
            $table->string('alt_text', 255)->nullable();
            $table->unsignedInteger('thu_tu')->default(0);
            $table->timestamps();
        });

        $migration = require database_path('migrations/2026_10_22_000001_extend_product_catalog.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('san_pham', 'slug'));
        $this->assertTrue(Schema::hasColumn('san_pham', 'trang_thai_hien_thi'));
        $this->assertTrue(Schema::hasColumn('san_pham', 'source_url'));
        $this->assertTrue(Schema::hasColumn('san_pham', 'thong_so_ky_thuat'));
        $this->assertTrue(Schema::hasTable('san_pham_hinh_anh'));
        $this->assertTrue(collect(Schema::getForeignKeys('san_pham_hinh_anh'))->contains(
            fn (array $key): bool => $key['columns'] === ['ma_sp']
                && $key['foreign_table'] === 'san_pham',
        ));
        $this->assertSame('draft', DB::table('san_pham')->value('trang_thai_hien_thi'));

        $migration->down();

        $this->assertTrue(Schema::hasTable('san_pham'));
        $this->assertFalse(Schema::hasColumn('san_pham', 'slug'));
        $this->assertSame(1, DB::table('san_pham')->count());
    }

    public function test_catalog_migration_can_create_the_catalog_on_a_fresh_database(): void
    {
        Schema::dropIfExists('san_pham_hinh_anh');
        Schema::dropIfExists('san_pham');

        $migration = require database_path('migrations/2026_10_22_000001_extend_product_catalog.php');
        $migration->up();

        $this->assertTrue(Schema::hasTable('san_pham'));
        $this->assertTrue(Schema::hasColumn('san_pham', 'source_url'));
        $this->assertTrue(Schema::hasTable('san_pham_hinh_anh'));
    }

    public function test_product_import_is_idempotent_and_keeps_products_as_drafts(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'techstore-import-');
        file_put_contents($path, implode("\n", [
            json_encode([
                'url' => 'https://example.test/products/laptop-model.html',
                'name' => 'Laptop Model',
                'category' => 'Laptop',
                'brand' => 'Brand A',
                'sale_price' => 15000000,
                'list_price' => 18000000,
                'description' => 'Sample description',
                'specifications' => ['ram' => '16 GB', 'cpu' => 'Model X'],
            ], JSON_THROW_ON_ERROR),
            json_encode([
                'url' => 'https://example.test/products/unknown-price.html',
                'name' => 'Unknown Price',
                'category' => 'Laptop',
                'sale_price' => 0,
            ], JSON_THROW_ON_ERROR),
        ])."\n");

        try {
            $this->artisan('catalog:import', ['file' => $path])
                ->expectsOutputToContain('Imported or updated 2 products as draft')
                ->assertSuccessful();

            $product = DB::table('san_pham')->where('ten_sp', 'Laptop Model')->first();
            $this->assertNotNull($product);
            $this->assertSame('draft', $product->trang_thai_hien_thi);
            $this->assertSame(0, (int) $product->so_luong);
            $this->assertSame('https://example.test/products/laptop-model.html', $product->source_url);
            $this->assertSame(15000000, (int) $product->gia_ban);
            $this->assertSame(18000000, (int) $product->gia_niem_yet);
            $this->assertSame(['ram' => '16 GB', 'cpu' => 'Model X'], json_decode($product->thong_so_ky_thuat, true));

            DB::table('san_pham')->where('ma_sp', $product->ma_sp)->update([
                'so_luong' => 5,
                'trang_thai_hien_thi' => 'published',
            ]);

            $this->artisan('catalog:import', ['file' => $path])->assertSuccessful();

            $product = DB::table('san_pham')->where('ma_sp', $product->ma_sp)->first();
            $this->assertSame('published', $product->trang_thai_hien_thi);
            $this->assertSame(5, (int) $product->so_luong);
            $this->assertSame(2, DB::table('san_pham')->count());
        } finally {
            unlink($path);
        }
    }

    private function createLegacyProductTable(): void
    {
        Schema::create('san_pham', function (Blueprint $table) {
            $table->char('ma_sp', 10)->primary();
            $table->string('ten_sp')->nullable();
            $table->string('loai', 20)->nullable();
            $table->string('thuong_hieu', 100)->nullable();
            $table->string('mo_ta')->nullable();
            $table->decimal('gia_ban', 10, 0)->nullable();
            $table->integer('so_luong')->nullable();
            $table->integer('stt');
            $table->string('slug')->nullable()->unique();
            $table->string('trang_thai_hien_thi', 20)->default('draft')->index();
            $table->decimal('gia_niem_yet', 10, 0)->nullable();
            $table->string('source_url', 512)->nullable()->unique();
            $table->longText('thong_so_ky_thuat')->nullable();
        });
        Schema::create('san_pham_hinh_anh', function (Blueprint $table) {
            $table->bigIncrements('id_hinh_anh');
            $table->char('ma_sp', 10);
            $table->string('duong_dan_anh', 2048);
            $table->string('alt_text')->nullable();
            $table->unsignedInteger('thu_tu')->default(0);
            $table->timestamps();
        });
    }

    private function insertProduct(
        string $id,
        string $name,
        string $category,
        string $brand,
        int $price,
        int $stock,
        string $slug,
        string $status = 'published',
    ): void {
        DB::table('san_pham')->insert([
            'ma_sp' => $id,
            'ten_sp' => $name,
            'loai' => $category,
            'thuong_hieu' => $brand,
            'mo_ta' => null,
            'gia_ban' => $price,
            'so_luong' => $stock,
            'stt' => 0,
            'slug' => $slug,
            'trang_thai_hien_thi' => $status,
            'gia_niem_yet' => null,
        ]);
    }
}
