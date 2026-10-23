<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddDemoProductReviews extends Command
{
    protected $signature = 'reviews:add-demo {--limit=3 : Number of published products to add an explicitly labelled demo review to}';

    protected $description = 'Add clearly labelled sample reviews without affecting real customer ratings';

    public function handle(): int
    {
        if (! Schema::hasTable('danh_gia_mau')) {
            $this->components->error('Run php artisan migrate before adding demo reviews.');

            return self::FAILURE;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) {
            $this->components->error('The review limit must be between 1 and 100.');

            return self::FAILURE;
        }

        $products = DB::table('san_pham as p')
            ->leftJoin('danh_gia_mau as r', 'r.ma_sp', '=', 'p.ma_sp')
            ->where('p.trang_thai_hien_thi', 'published')
            ->where('p.gia_ban', '>', 0)
            ->where('p.so_luong', '>', 0)
            ->whereNull('p.deleted_at')
            ->whereNull('r.id_danh_gia_mau')
            ->orderBy('p.stt')
            ->limit($limit)
            ->get(['p.ma_sp', 'p.ten_sp']);

        foreach ($products as $product) {
            DB::table('danh_gia_mau')->insert([
                'ma_sp' => $product->ma_sp,
                'name' => 'TechStore · đánh giá mẫu',
                'rating' => 5,
                'content' => 'ĐÁNH GIÁ MẪU — Nội dung minh họa giao diện, không phải phản hồi của khách hàng thật về '.$product->ten_sp.'.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->components->info("Added {$products->count()} clearly labelled demo reviews; real ratings are unchanged.");

        return self::SUCCESS;
    }
}
