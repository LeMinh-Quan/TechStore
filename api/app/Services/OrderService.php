<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function indexForCustomer(string $customerId): array
    {
        $orders = DB::table('don_hang')
            ->where('ma_kh', $customerId)
            ->orderByDesc('ngay_dat')
            ->paginate(20);

        return [
            'data' => $orders->getCollection(),
            'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage()],
        ];
    }

    public function findForCustomer(string $customerId, string $orderId): ?array
    {
        $order = DB::table('don_hang')
            ->where('ma_dh', $orderId)
            ->where('ma_kh', $customerId)
            ->first();
        if (! $order) {
            return null;
        }

        return [
            'order' => $order,
            'payment' => DB::table('thanh_toan')->where('ma_dh', $orderId)->first(),
            'items' => DB::table('chi_tiet_don_hang')
                ->join('san_pham', 'san_pham.ma_sp', '=', 'chi_tiet_don_hang.ma_sp')
                ->where('chi_tiet_don_hang.ma_dh', $orderId)
                ->select('chi_tiet_don_hang.*')
                ->selectRaw('COALESCE(chi_tiet_don_hang.ten_sp_snapshot, san_pham.ten_sp) as ten_sp')
                ->get(),
            'history' => DB::table('nhat_ky_trang_thai_don')
                ->where('ma_dh', $orderId)
                ->orderBy('thoi_gian_tao')
                ->get(),
        ];
    }

    public function cancelForCustomer(
        string $customerId,
        string $orderId,
        OrderStatusService $statuses,
        VoucherService $vouchers,
    ): bool {
        return DB::transaction(function () use ($customerId, $orderId, $statuses, $vouchers): bool {
            $order = DB::table('don_hang')
                ->where('ma_dh', $orderId)
                ->where('ma_kh', $customerId)
                ->lockForUpdate()
                ->first();
            if (! $order) {
                return false;
            }
            if ($order->trang_thai !== OrderStatusService::PENDING) {
                throw ValidationException::withMessages(['order' => ['Chỉ có thể hủy đơn đang chờ xác nhận.']]);
            }

            $payment = DB::table('thanh_toan')->where('ma_dh', $orderId)->lockForUpdate()->first();
            if ($payment?->trang_thai === 'Da Thanh Toan') {
                throw ValidationException::withMessages(['order' => ['Đơn đã thanh toán; vui lòng liên hệ cửa hàng để được hỗ trợ hoàn tiền.']]);
            }

            $items = DB::table('chi_tiet_don_hang')->where('ma_dh', $orderId)->get();
            foreach ($items as $item) {
                DB::table('san_pham')->where('ma_sp', $item->ma_sp)->increment('so_luong', (int) $item->so_luong);
            }

            $legacySerials = Schema::hasColumn('so_serial_san_pham', 'id_serial');
            DB::table('so_serial_san_pham')->where('ma_dh', $orderId)
                ->when(! $legacySerials, fn ($query) => $query->where('status', 'Reserved'))
                ->update($legacySerials
                    ? ['ma_dh' => null]
                    : ['status' => 'In_Stock', 'ma_dh' => null, 'updated_at' => now()]);
            $statuses->transition(
                $orderId,
                $order->trang_thai,
                OrderStatusService::CANCELLED,
                "customer:{$customerId}",
                'Khách hàng hủy đơn trước khi xác nhận.',
            );
            if ($order->ma_voucher) {
                $vouchers->refund($order->ma_voucher, $customerId, $orderId);
            }
            app(CustomerNotificationService::class)->create(
                $customerId,
                'order.cancelled',
                'Đơn hàng đã được hủy',
                "Đơn hàng {$orderId} đã được hủy.",
                $orderId,
            );

            return true;
        });
    }

    public function warrantiesForCustomer(string $customerId): array
    {
        $legacySerials = Schema::hasColumn('so_serial_san_pham', 'id_serial');

        return DB::table('bao_hanh as w')
            ->join(
                'so_serial_san_pham as s',
                $legacySerials ? 's.id_serial' : 's.id',
                '=',
                $legacySerials ? 'w.id_serial' : 'w.product_serial_id',
            )
            ->join('san_pham as p', 'p.ma_sp', '=', 's.ma_sp')
            ->where('w.ma_kh', $customerId)
            ->select([
                'p.ten_sp as product_name',
                $legacySerials ? 's.so_serial as serial_number' : 's.serial_number',
                'w.ma_dh',
                $legacySerials ? 'w.ngay_bat_dau as starts_at' : 'w.starts_at',
                $legacySerials ? 'w.ngay_het_han as expires_at' : 'w.expires_at',
                $legacySerials ? DB::raw("'active' as status") : 'w.status',
            ])
            ->orderByDesc($legacySerials ? 'w.ngay_bat_dau' : 'w.starts_at')
            ->get()
            ->all();
    }
}
