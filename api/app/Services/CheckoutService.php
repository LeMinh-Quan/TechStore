<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly EducationDiscountService $educationDiscounts,
        private readonly OrderStatusService $statuses,
        private readonly CustomerNotificationService $notifications,
    ) {}

    public function placeOrder(array $data, string $customerId): array
    {
        $existing = Order::query()
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();
        if ($existing) {
            return $this->replayResult($existing, $customerId);
        }

        try {
            return DB::transaction(function () use ($data, $customerId): array {
                $cart = DB::table('gio_hang')
                    ->where('ma_kh', $customerId)
                    ->orderByDesc('ngay_tao')
                    ->lockForUpdate()
                    ->first();
                if (! $cart) {
                    throw ValidationException::withMessages(['cart' => ['Giỏ hàng đang trống.']]);
                }

                $items = DB::table('chi_tiet_gio_hang')
                    ->where('ma_cart', $cart->ma_cart)
                    ->orderBy('ma_sp')
                    ->lockForUpdate()
                    ->get();
                if ($items->isEmpty()) {
                    throw ValidationException::withMessages(['cart' => ['Giỏ hàng đang trống.']]);
                }

                $orderId = Str::upper(Str::random(10));
                $subtotal = 0;
                $orderLines = [];
                $serialIds = [];
                $legacySerials = Schema::hasColumn('so_serial_san_pham', 'id_serial');
                $serialIdColumn = $legacySerials ? 'id_serial' : 'id';
                $serialStatusColumn = $legacySerials ? 'trang_thai' : 'status';

                foreach ($items as $item) {
                    $product = DB::table('san_pham')
                        ->where('ma_sp', $item->ma_sp)
                        ->whereNull('deleted_at')
                        ->lockForUpdate()
                        ->first();
                    if (! $product || $product->trang_thai_hien_thi !== 'published'
                        || (int) $product->gia_ban <= 0 || (int) $product->so_luong < (int) $item->so_luong) {
                        throw ValidationException::withMessages([
                            'cart' => ['Sản phẩm đã thay đổi hoặc không đủ tồn kho. Vui lòng cập nhật giỏ hàng.'],
                        ]);
                    }

                    $subtotal += (int) $product->gia_ban * (int) $item->so_luong;
                    $orderLines[] = [
                        'ma_dh' => $orderId,
                        'ma_sp' => $product->ma_sp,
                        'so_luong' => $item->so_luong,
                        'don_gia' => $product->gia_ban,
                        'ten_sp_snapshot' => $product->ten_sp,
                        'thong_so_snapshot' => $product->thong_so_ky_thuat ?? null,
                    ];
                    DB::table('san_pham')->where('ma_sp', $product->ma_sp)
                        ->decrement('so_luong', (int) $item->so_luong);

                    $serialTrackingEnabled = DB::table('so_serial_san_pham')
                        ->where('ma_sp', $product->ma_sp)
                        ->exists();
                    if ($serialTrackingEnabled) {
                        $serials = DB::table('so_serial_san_pham')
                            ->where('ma_sp', $product->ma_sp)
                            ->where($serialStatusColumn, 'In_Stock')
                            ->when($legacySerials, fn ($query) => $query->whereNull('ma_dh'))
                            ->orderBy($serialIdColumn)
                            ->lockForUpdate()
                            ->limit((int) $item->so_luong)
                            ->get();
                        if ($serials->count() !== (int) $item->so_luong) {
                            throw ValidationException::withMessages([
                                'cart' => ['Số serial còn sẵn không khớp tồn kho cho một sản phẩm. Vui lòng liên hệ cửa hàng.'],
                            ]);
                        }
                        $serialIds = array_merge($serialIds, $serials->pluck($serialIdColumn)->all());
                    }
                }

                $discount = 0;
                $voucher = null;
                $promotionType = null;
                $educationDiscount = $this->educationDiscounts->discountForCustomer($customerId, $subtotal);
                if (! empty($data['voucher_code'])) {
                    $voucher = $this->vouchers->usableVoucher($data['voucher_code'], $subtotal, true, $customerId);
                    $voucherDiscount = $this->vouchers->discountFor($voucher, $subtotal);
                    if ($voucherDiscount > $educationDiscount) {
                        $discount = $voucherDiscount;
                        $promotionType = 'voucher';
                    } else {
                        $voucher = null;
                    }
                }
                if ($voucher === null && $educationDiscount > 0) {
                    $discount = $educationDiscount;
                    $promotionType = 'education';
                }
                $total = $subtotal - $discount;

                Order::query()->create([
                    'ma_dh' => $orderId,
                    'idempotency_key' => $data['idempotency_key'],
                    'ma_kh' => $customerId,
                    'ngay_dat' => now()->toDateString(),
                    'tong_tien' => $total,
                    'ma_voucher' => $voucher?->code,
                    'tien_giam' => $discount,
                    'loai_uu_dai' => $promotionType,
                    'trang_thai' => 'Cho xac nhan',
                    'ten_nguoi_nhan' => $data['recipient_name'],
                    'dien_thoai_nguoi_nhan' => $data['phone'],
                    'dia_chi_giao_hang' => implode(', ', array_filter([
                        $data['address'],
                        $data['ward'] ?? null,
                        $data['district'],
                        $data['city'],
                    ])),
                ]);
                if ($serialIds !== []) {
                    DB::table('so_serial_san_pham')->whereIn($serialIdColumn, $serialIds)
                        ->update($legacySerials
                            ? ['ma_dh' => $orderId]
                            : ['status' => 'Reserved', 'ma_dh' => $orderId, 'updated_at' => now()]);
                }
                DB::table('chi_tiet_don_hang')->insert($orderLines);
                DB::table('thanh_toan')->insert([
                    'ma_tt' => Str::upper(Str::random(10)),
                    'ma_dh' => $orderId,
                    'phuong_thuc' => match ($data['payment_method']) {
                        'transfer' => 'Chuyen khoan',
                        'momo' => 'MoMo',
                        default => 'Tien mat',
                    },
                    'ngay_va_gio' => now(),
                    'trang_thai' => 'Chua Thanh Toan',
                ]);
                $this->statuses->recordInitial(
                    $orderId,
                    OrderStatusService::PENDING,
                    "customer:{$customerId}",
                    'Khách hàng đặt đơn.',
                );
                $this->notifications->create(
                    $customerId,
                    'order.created',
                    'Đặt hàng thành công',
                    "Đơn hàng {$orderId} đã được tạo và đang chờ cửa hàng xác nhận.",
                    $orderId,
                );
                DB::table('chi_tiet_gio_hang')->where('ma_cart', $cart->ma_cart)->delete();
                if ($voucher) {
                    $this->vouchers->redeem($voucher, $customerId, $orderId, $discount);
                }

                return [
                    'id' => $orderId,
                    'total' => $total,
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'replayed' => false,
                    'conflict' => false,
                ];
            }, 3);
        } catch (QueryException $exception) {
            $existing = Order::query()
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if (! $existing) {
                throw $exception;
            }

            return $this->replayResult($existing, $customerId);
        }
    }

    private function replayResult(object $order, string $customerId): array
    {
        return [
            'id' => $order->ma_dh,
            'total' => (int) $order->tong_tien,
            'replayed' => true,
            'conflict' => $order->ma_kh !== $customerId,
        ];
    }
}
