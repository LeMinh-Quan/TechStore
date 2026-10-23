<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderStatusService
{
    public const PENDING = 'Cho xac nhan';

    public const SHIPPING = 'Dang Giao';

    public const DELIVERED = 'Da Giao';

    public const CANCELLED = 'huy';

    private const TRANSITIONS = [
        self::PENDING => [self::SHIPPING, self::CANCELLED],
        self::SHIPPING => [self::DELIVERED, self::CANCELLED],
        self::DELIVERED => [],
        self::CANCELLED => [],
    ];

    public function transition(string $orderId, string $from, string $to, string $actor, string $note): bool
    {
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => ['Không thể chuyển trạng thái đơn hàng theo quy trình hiện tại.'],
            ]);
        }

        DB::table('don_hang')->where('ma_dh', $orderId)->update(['trang_thai' => $to]);
        DB::table('nhat_ky_trang_thai_don')->insert([
            'ma_dh' => $orderId,
            'trang_thai_cu' => $from,
            'trang_thai' => $to,
            'nguoi_thuc_hien' => $actor,
            'ghi_chu' => $note,
            'thoi_gian_tao' => now(),
        ]);

        return true;
    }

    public function recordInitial(string $orderId, string $status, string $actor, string $note): void
    {
        DB::table('nhat_ky_trang_thai_don')->insert([
            'ma_dh' => $orderId,
            'trang_thai_cu' => null,
            'trang_thai' => $status,
            'nguoi_thuc_hien' => $actor,
            'ghi_chu' => $note,
            'thoi_gian_tao' => now(),
        ]);
    }
}
