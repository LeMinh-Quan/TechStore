<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CATEGORY_ORDER_INDEX = 'san_pham_catalog_category_order_idx';

    private const CATEGORY_BRAND_ORDER_INDEX = 'san_pham_catalog_category_brand_order_idx';

    public function up(): void
    {
        if (! Schema::hasTable('san_pham')
            || ! Schema::hasColumn('san_pham', 'trang_thai_hien_thi')
            || ! Schema::hasColumn('san_pham', 'loai')
            || ! Schema::hasColumn('san_pham', 'stt')
            || ! Schema::hasColumn('san_pham', 'ma_sp')) {
            return;
        }

        if (! Schema::hasIndex('san_pham', self::CATEGORY_ORDER_INDEX)) {
            Schema::table('san_pham', function (Blueprint $table): void {
                $table->index(
                    ['trang_thai_hien_thi', 'loai', 'stt', 'ma_sp'],
                    self::CATEGORY_ORDER_INDEX,
                );
            });
        }

        if (Schema::hasColumn('san_pham', 'thuong_hieu')
            && ! Schema::hasIndex('san_pham', self::CATEGORY_BRAND_ORDER_INDEX)) {
            Schema::table('san_pham', function (Blueprint $table): void {
                $table->index(
                    ['trang_thai_hien_thi', 'loai', 'thuong_hieu', 'stt', 'ma_sp'],
                    self::CATEGORY_BRAND_ORDER_INDEX,
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('san_pham')) {
            return;
        }

        foreach ([self::CATEGORY_BRAND_ORDER_INDEX, self::CATEGORY_ORDER_INDEX] as $index) {
            if (Schema::hasIndex('san_pham', $index)) {
                Schema::table('san_pham', fn (Blueprint $table) => $table->dropIndex($index));
            }
        }
    }
};
