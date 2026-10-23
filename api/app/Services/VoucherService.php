<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class VoucherService
{
    public function usableVoucher(
        string $code,
        int $orderTotal,
        bool $lockForUpdate = false,
        ?string $customerId = null,
    ): object {
        $legacySchema = Schema::hasColumn('phieu_giam_gia', 'ma_code');
        $query = DB::table('phieu_giam_gia')
            ->where($legacySchema ? 'ma_code' : 'code', strtoupper(trim($code)));
        $voucher = $lockForUpdate ? $query->lockForUpdate()->first() : $query->first();
        if (! $voucher) {
            throw ValidationException::withMessages(['code' => ['Không tìm thấy mã giảm giá.']]);
        }

        if ($voucher && $legacySchema) {
            $voucher = (object) [
                'code' => $voucher->ma_code,
                'discount_type' => $voucher->loai_giam === 'percentage' ? 'percent' : 'fixed',
                'discount_value' => $voucher->gia_tri_giam,
                'minimum_order_value' => $voucher->gia_tri_don_toi_thieu,
                'maximum_discount' => $voucher->giam_toi_da,
                'usage_limit' => $voucher->so_luong,
                'usage_count' => $voucher->da_dung,
                'starts_at' => $voucher->ngay_bat_dau,
                'expires_at' => $voucher->ngay_ket_thuc,
                'is_active' => $voucher->trang_thai,
            ];
        }
        if (! $voucher->is_active) {
            throw ValidationException::withMessages(['code' => ['Mã giảm giá hiện không được kích hoạt.']]);
        }
        if (($voucher->starts_at && now()->lt($voucher->starts_at))
            || ($voucher->expires_at && now()->gt($voucher->expires_at))) {
            throw ValidationException::withMessages(['code' => ['Mã giảm giá chưa bắt đầu hoặc đã hết hạn.']]);
        }
        if ($voucher->usage_limit !== null && $voucher->usage_count >= $voucher->usage_limit) {
            throw ValidationException::withMessages(['code' => ['Mã giảm giá đã hết lượt sử dụng.']]);
        }
        if ($orderTotal < (int) $voucher->minimum_order_value) {
            throw ValidationException::withMessages(['code' => ['Đơn hàng chưa đạt giá trị tối thiểu để áp dụng mã.']]);
        }
        if ($customerId !== null && DB::table('lich_su_su_dung_voucher')
            ->where('code', $voucher->code)
            ->where('ma_kh', $customerId)
            ->exists()) {
            throw ValidationException::withMessages(['code' => ['Bạn đã sử dụng mã giảm giá này trước đó.']]);
        }
        if ($customerId !== null && Schema::hasTable('voucher_da_thu_thap')
            && ! DB::table('voucher_da_thu_thap')
                ->where('code', $voucher->code)
                ->where('ma_kh', $customerId)
                ->exists()) {
            throw ValidationException::withMessages(['code' => ['Bạn cần thu thập mã giảm giá trước khi sử dụng.']]);
        }

        return $voucher;
    }

    public function discountFor(object $voucher, int $orderTotal): int
    {
        $amount = $voucher->discount_type === 'percent'
            ? (int) floor($orderTotal * (int) $voucher->discount_value / 100)
            : (int) $voucher->discount_value;
        if ($voucher->maximum_discount !== null) {
            $amount = min($amount, (int) $voucher->maximum_discount);
        }

        return min($orderTotal, $amount);
    }

    public function redeem(object $voucher, string $customerId, string $orderId, int $discount): void
    {
        $legacySchema = Schema::hasColumn('phieu_giam_gia', 'ma_code');
        $codeColumn = $legacySchema ? 'ma_code' : 'code';
        $countColumn = $legacySchema ? 'da_dung' : 'usage_count';
        $limitColumn = $legacySchema ? 'so_luong' : 'usage_limit';
        $query = DB::table('phieu_giam_gia')->where($codeColumn, $voucher->code);

        if ($voucher->usage_limit !== null) {
            $query->whereColumn($countColumn, '<', $limitColumn);
        }

        if ($query->increment($countColumn) !== 1) {
            throw ValidationException::withMessages(['code' => ['Mã giảm giá đã hết lượt sử dụng.']]);
        }

        DB::table('lich_su_su_dung_voucher')->insert([
            'code' => $voucher->code,
            'ma_kh' => $customerId,
            'ma_dh' => $orderId,
            'discount_amount' => $discount,
            'created_at' => now(),
        ]);
    }

    public function refund(string $code, string $customerId, string $orderId): void
    {
        $refunded = DB::table('lich_su_su_dung_voucher')
            ->where('code', $code)
            ->where('ma_kh', $customerId)
            ->where('ma_dh', $orderId)
            ->delete();

        if ($refunded === 0) {
            return;
        }

        $legacySchema = Schema::hasColumn('phieu_giam_gia', 'ma_code');
        DB::table('phieu_giam_gia')
            ->where($legacySchema ? 'ma_code' : 'code', $code)
            ->where($legacySchema ? 'da_dung' : 'usage_count', '>', 0)
            ->decrement($legacySchema ? 'da_dung' : 'usage_count');
    }
}
