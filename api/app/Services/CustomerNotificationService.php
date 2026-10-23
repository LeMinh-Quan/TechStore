<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CustomerNotificationService
{
    public function create(?string $customerId, string $type, string $title, string $message, ?string $orderId = null): void
    {
        if ($customerId === null) {
            return;
        }

        DB::table('thong_bao_nguoi_dung')->insert([
            'ma_kh' => $customerId,
            'loai' => $type,
            'tieu_de' => $title,
            'noi_dung' => $message,
            'ma_dh' => $orderId,
            'created_at' => now(),
        ]);
    }

    public function listForCustomer(string $customerId): LengthAwarePaginator
    {
        return DB::table('thong_bao_nguoi_dung')
            ->where('ma_kh', $customerId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);
    }

    public function markRead(string $customerId, int $notificationId): bool
    {
        return DB::table('thong_bao_nguoi_dung')
            ->where('ma_kh', $customerId)
            ->where('id', $notificationId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]) > 0;
    }

    public function markAllRead(string $customerId): int
    {
        return DB::table('thong_bao_nguoi_dung')
            ->where('ma_kh', $customerId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
