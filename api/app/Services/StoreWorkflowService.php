<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class StoreWorkflowService
{
    public function reviews(string $slug): ?array
    {
        $product = DB::table('san_pham')->where('slug', $slug)
            ->whereNull('deleted_at')
            ->first();
        if (! $product) {
            return null;
        }

        $legacyReviews = Schema::hasColumn('danh_gia', 'so_sao');
        $nameColumn = Schema::hasColumn('nguoi_dung', 'ten_khach_hang') ? 'ten_khach_hang' : 'ten_dang_nhap';
        $ratingColumn = $legacyReviews ? 'so_sao' : 'rating';
        $contentColumn = $legacyReviews ? 'binh_luan' : 'content';
        $createdColumn = $legacyReviews ? 'thoi_gian_tao' : 'created_at';
        $reviews = DB::table('danh_gia as r')
            ->join('nguoi_dung as u', 'u.ma_kh', '=', 'r.ma_kh')
            ->where('r.ma_sp', $product->ma_sp)
            ->select(["r.{$ratingColumn} as rating", "r.{$contentColumn} as content", "r.{$createdColumn} as created_at", "u.{$nameColumn} as name"])
            ->orderByDesc("r.{$createdColumn}")
            ->paginate(20);
        $demoReviews = Schema::hasTable('danh_gia_mau')
            ? DB::table('danh_gia_mau')
                ->where('ma_sp', $product->ma_sp)
                ->select(['rating', 'content', 'created_at', 'name'])
                ->get()
                ->map(fn (object $review): object => (object) ((array) $review + ['is_demo' => true]))
            : collect();

        return [
            'data' => $reviews->getCollection()
                ->map(fn (object $review): object => (object) ((array) $review + ['is_demo' => false]))
                ->concat($demoReviews)
                ->sortByDesc('created_at')
                ->values(),
            'meta' => [
                'average_rating' => round((float) DB::table('danh_gia')->where('ma_sp', $product->ma_sp)->avg($ratingColumn), 1),
                'review_count' => $reviews->total(),
                'demo_review_count' => $demoReviews->count(),
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
            ],
        ];
    }

    public function storeReview(string $customerId, string $productId, array $data): bool
    {
        $eligible = DB::table('don_hang as o')
            ->join('chi_tiet_don_hang as i', 'i.ma_dh', '=', 'o.ma_dh')
            ->where('o.ma_kh', $customerId)
            ->where('o.trang_thai', 'Da Giao')
            ->where('i.ma_sp', $productId)
            ->exists();
        if (! $eligible) {
            throw ValidationException::withMessages(['product_id' => ['Chỉ khách hàng đã nhận sản phẩm mới có thể đánh giá.']]);
        }

        $productExists = DB::table('san_pham')->where('ma_sp', $productId)->whereNull('deleted_at')->exists();
        if (! $productExists) {
            return false;
        }

        $legacyReviews = Schema::hasColumn('danh_gia', 'so_sao');
        $review = [
            ($legacyReviews ? 'so_sao' : 'rating') => $data['rating'],
            ($legacyReviews ? 'binh_luan' : 'content') => $data['content'],
        ];
        if ($legacyReviews) {
            $updated = DB::table('danh_gia')
                ->where('ma_kh', $customerId)
                ->where('ma_sp', $productId)
                ->update($review);
            if (! $updated && ! DB::table('danh_gia')->where('ma_kh', $customerId)->where('ma_sp', $productId)->exists()) {
                DB::table('danh_gia')->insert($review + [
                    'ma_kh' => $customerId,
                    'ma_sp' => $productId,
                    'thoi_gian_tao' => now(),
                ]);
            }
        } else {
            DB::table('danh_gia')->updateOrInsert(
                ['ma_kh' => $customerId, 'ma_sp' => $productId],
                $review + ['updated_at' => now(), 'created_at' => now()],
            );
        }

        return true;
    }

    public function addresses(string $customerId): array
    {
        return DB::table('dia_chi')
            ->where('ma_kh', $customerId)
            ->orderBy('loai_dia_chi')
            ->get()
            ->all();
    }

    public function storeAddress(string $customerId, array $data): void
    {
        DB::table('dia_chi')->updateOrInsert(
            ['ma_kh' => $customerId, 'loai_dia_chi' => $data['label']],
            [
                'ten_nguoi_nhan' => $data['recipient_name'],
                'so_dien_thoai' => $data['phone'],
                'chi_tiet_dia_chi' => $data['address'],
                'phuong_xa' => $data['ward'] ?? null,
                'quan_huyen' => $data['district'],
                'thanh_pho' => $data['city'],
                'ghi_chu' => null,
                'dia_chi_giao_hang' => in_array($data['label'], ['Nha', 'CongTy'], true) ? $data['label'] : 'Khac',
            ],
        );
    }

    public function deleteAddress(string $customerId, string $label): bool
    {
        return DB::table('dia_chi')
            ->where('ma_kh', $customerId)
            ->where('loai_dia_chi', $label)
            ->delete() > 0;
    }

    public function validateVoucher(string $customerId, string $code, VoucherService $vouchers): array
    {
        $cart = DB::table('gio_hang')
            ->where('ma_kh', $customerId)
            ->orderByDesc('ngay_tao')
            ->first();
        if (! $cart) {
            throw ValidationException::withMessages(['cart' => ['Giỏ hàng đang trống.']]);
        }

        $items = DB::table('chi_tiet_gio_hang as i')
            ->join('san_pham as p', 'p.ma_sp', '=', 'i.ma_sp')
            ->where('i.ma_cart', $cart->ma_cart)
            ->select(['p.gia_ban', 'p.so_luong as stock', 'p.trang_thai_hien_thi', 'p.deleted_at', 'i.so_luong as quantity'])
            ->get();
        if ($items->isEmpty() || $items->count() !== DB::table('chi_tiet_gio_hang')->where('ma_cart', $cart->ma_cart)->count()) {
            throw ValidationException::withMessages(['cart' => ['Giỏ hàng có sản phẩm không còn khả dụng.']]);
        }

        $total = 0;
        foreach ($items as $item) {
            if ($item->deleted_at !== null || $item->trang_thai_hien_thi !== 'published'
                || (int) $item->gia_ban <= 0 || (int) $item->quantity > (int) $item->stock) {
                throw ValidationException::withMessages(['cart' => ['Giỏ hàng có sản phẩm không còn khả dụng hoặc không đủ tồn kho.']]);
            }
            $total += (int) $item->gia_ban * (int) $item->quantity;
        }

        $voucher = $vouchers->usableVoucher($code, $total, false, $customerId);
        $discount = $vouchers->discountFor($voucher, $total);

        return [
            'code' => $voucher->code,
            'discount' => $discount,
            'total' => max(0, $total - $discount),
        ];
    }
}
