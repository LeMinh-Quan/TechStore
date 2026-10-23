<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PublishPricedCatalogProducts extends Command
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

    protected $signature = 'catalog:publish-priced
        {--apply : Publish eligible products in MySQL (default is preview only)}';

    protected $description = 'Publish priced in-stock products that are not hidden from the public catalog';

    public function handle(): int
    {
        $query = DB::table('san_pham')
            ->where('trang_thai_hien_thi', 'draft')
            ->where('gia_ban', '>', 0)
            ->where('so_luong', '>', 0)
            ->where('loai', '!=', 'Khác')
            ->whereNull('deleted_at')
            ->where('la_hang_cu', false);

        foreach (self::HIDDEN_NAME_PATTERNS as $pattern) {
            $query->where('ten_sp', 'not like', $pattern);
        }

        foreach (self::PHONE_ACCESSORY_NAME_PATTERNS as $pattern) {
            $query->where(function ($query) use ($pattern): void {
                $query->where('loai', '!=', 'Điện thoại')
                    ->orWhere('ten_sp', 'not like', $pattern);
            });
        }

        $ids = $query->pluck('ma_sp');
        if (! $this->option('apply')) {
            $this->components->info("Preview only: {$ids->count()} priced, in-stock public products would be published.");

            return self::SUCCESS;
        }

        $updated = DB::table('san_pham')
            ->whereIn('ma_sp', $ids)
            ->where('trang_thai_hien_thi', 'draft')
            ->update(['trang_thai_hien_thi' => 'published']);

        $this->components->info("Published {$updated} priced, in-stock products; hidden categories and products remain excluded.");

        return self::SUCCESS;
    }
}
