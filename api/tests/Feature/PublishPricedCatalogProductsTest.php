<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublishPricedCatalogProductsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('san_pham', function (Blueprint $table): void {
            $table->char('ma_sp', 10)->primary();
            $table->string('ten_sp');
            $table->string('loai');
            $table->decimal('gia_ban', 10, 0);
            $table->integer('so_luong');
            $table->string('trang_thai_hien_thi', 20);
            $table->timestamp('deleted_at')->nullable();
            $table->boolean('la_hang_cu')->default(false);
        });
    }

    public function test_it_publishes_only_priced_in_stock_products_in_the_public_catalog(): void
    {
        DB::table('san_pham')->insert([
            $this->product('LAPTOP0001', 'Laptop A', 'Laptop', 1000000, 12),
            $this->product('LAPTOP0002', 'Laptop B', 'Laptop', 0, 12),
            $this->product('LAPTOP0003', 'Laptop C', 'Laptop', 1000000, 0),
            $this->product('OLDITEM001', 'Laptop cũ', 'Laptop', 1000000, 12, true),
            $this->product('HIDDEN0001', 'Ốp lưng điện thoại', 'Điện thoại', 1000000, 12),
            $this->product('OTHER00001', 'Sản phẩm khác', 'Khác', 1000000, 12),
            $this->product('SERVICE001', 'Thay RAM laptop Corsair', 'Laptop', 1000000, 12),
        ]);

        $this->artisan('catalog:publish-priced', ['--apply' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('san_pham', ['ma_sp' => 'LAPTOP0001', 'trang_thai_hien_thi' => 'published']);
        $this->assertDatabaseHas('san_pham', ['ma_sp' => 'SERVICE001', 'trang_thai_hien_thi' => 'published']);
        foreach (['LAPTOP0002', 'LAPTOP0003', 'OLDITEM001', 'HIDDEN0001', 'OTHER00001'] as $productId) {
            $this->assertDatabaseHas('san_pham', ['ma_sp' => $productId, 'trang_thai_hien_thi' => 'draft']);
        }
    }

    private function product(
        string $id,
        string $name,
        string $category,
        int $price,
        int $stock,
        bool $used = false,
    ): array {
        return [
            'ma_sp' => $id,
            'ten_sp' => $name,
            'loai' => $category,
            'gia_ban' => $price,
            'so_luong' => $stock,
            'trang_thai_hien_thi' => 'draft',
            'deleted_at' => null,
            'la_hang_cu' => $used,
        ];
    }
}
