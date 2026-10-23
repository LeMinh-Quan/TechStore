<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class StaffService
{
    public function companyRevenue(): array
    {
        $deliveredOrders = DB::table('don_hang')->where('trang_thai', 'Da Giao');
        $branches = DB::table('don_hang as o')
            ->join('cua_hang as s', 's.id_cua_hang', '=', 'o.id_cua_hang')
            ->where('o.trang_thai', 'Da Giao')
            ->select('s.id_cua_hang', 's.ten_cua_hang')
            ->selectRaw('SUM(o.tong_tien) as total_revenue')
            ->groupBy('s.id_cua_hang', 's.ten_cua_hang')
            ->orderBy('s.id_cua_hang')
            ->get();

        return [
            'total_revenue' => (int) $deliveredOrders->sum('tong_tien'),
            'branches' => $branches,
        ];
    }

    public function branchRevenue(int|string $branchId): int
    {
        return (int) DB::table('don_hang')
            ->where('trang_thai', 'Da Giao')
            ->where('id_cua_hang', $branchId)
            ->sum('tong_tien');
    }

    public function employeeRevenue(string $employeeId, int $month, int $year): int
    {
        return (int) DB::table('don_hang')
            ->where('trang_thai', 'Da Giao')
            ->where('nhan_vien_id', $employeeId)
            ->whereMonth('ngay_dat', $month)
            ->whereYear('ngay_dat', $year)
            ->sum('tong_tien');
    }

    public function updateStock(
        string $productId,
        int|string $branchId,
        string $adminId,
        int $newQuantity,
        ?string $ipAddress,
    ): array {
        return DB::transaction(function () use ($productId, $branchId, $adminId, $newQuantity, $ipAddress): array {
            $product = DB::table('san_pham')->where('ma_sp', $productId)
                ->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $product) {
                return ['not_found' => true];
            }
            if (DB::table('so_serial_san_pham')->where('ma_sp', $productId)->exists()) {
                throw ValidationException::withMessages([
                    'stock' => ['Tồn kho có quản lý serial chỉ được thay đổi qua phiếu nhập kho.'],
                ]);
            }

            $branchStock = DB::table('ton_kho_cua_hang')
                ->where('id_cua_hang', $branchId)
                ->where('ma_sp', $productId)
                ->lockForUpdate()
                ->first();
            $oldQuantity = (int) ($branchStock->so_luong_ton ?? 0);
            $difference = $newQuantity - $oldQuantity;
            if ((int) $product->so_luong + $difference < 0) {
                throw ValidationException::withMessages([
                    'stock' => ['Không thể giảm tồn kho chi nhánh vượt quá tổng tồn kho hiện có.'],
                ]);
            }

            DB::table('ton_kho_cua_hang')->updateOrInsert(
                ['id_cua_hang' => $branchId, 'ma_sp' => $productId],
                ['so_luong_ton' => $newQuantity],
            );
            if ($difference > 0) {
                DB::table('san_pham')->where('ma_sp', $productId)->increment('so_luong', $difference);
            } elseif ($difference < 0) {
                DB::table('san_pham')->where('ma_sp', $productId)->decrement('so_luong', abs($difference));
            }
            DB::table('nhat_ky_hoat_dong')->insert([
                'tk_admin' => $adminId,
                'hanh_dong' => 'inventory.branch_stock_updated',
                'chi_tiet' => "{$productId}: {$oldQuantity} -> {$newQuantity} @ {$branchId}",
                'dia_chi_ip' => $ipAddress,
                'thoi_gian_tao' => now(),
            ]);
            DB::table('nhat_ky_ton_kho')->insert([
                'ma_sp' => $productId,
                'id_cua_hang' => $branchId,
                'tk_admin' => $adminId,
                'ton_cu' => $oldQuantity,
                'ton_moi' => $newQuantity,
                'dia_chi_ip' => $ipAddress,
                'created_at' => now(),
            ]);

            return ['stock' => $newQuantity, 'total_stock' => (int) $product->so_luong + $difference];
        });
    }

    public function inventory(int|string $branchId, array $filters): LengthAwarePaginator
    {
        return DB::table('san_pham as p')
            ->leftJoin('ton_kho_cua_hang as b', function ($join) use ($branchId): void {
                $join->on('b.ma_sp', '=', 'p.ma_sp')->where('b.id_cua_hang', '=', $branchId);
            })
            ->whereNull('p.deleted_at')
            ->when(isset($filters['q']), function ($query) use ($filters): void {
                $term = '%'.addcslashes($filters['q'], '\\%_').'%';
                $query->where(function ($search) use ($term): void {
                    $search->where('p.ten_sp', 'like', $term)
                        ->orWhere('p.ma_sp', 'like', $term)
                        ->orWhere('p.thuong_hieu', 'like', $term);
                });
            })
            ->select([
                'p.ma_sp',
                'p.ten_sp',
                'p.loai',
                'p.thuong_hieu',
                'p.so_luong as total_stock',
                'b.so_luong_ton as branch_stock',
            ])
            ->selectSub(
                DB::table('ton_kho_cua_hang as branch_totals')
                    ->selectRaw('COALESCE(SUM(branch_totals.so_luong_ton), 0)')
                    ->whereColumn('branch_totals.ma_sp', 'p.ma_sp'),
                'allocated_stock',
            )
            ->selectRaw('CASE WHEN COALESCE(b.so_luong_ton, 0) <= 5 THEN 1 ELSE 0 END as low_stock')
            ->orderBy('p.ten_sp')
            ->paginate($filters['per_page'] ?? 20);
    }

    public function inventoryHistory(int|string $branchId, array $filters): LengthAwarePaginator
    {
        return DB::table('nhat_ky_ton_kho as h')
            ->join('san_pham as p', 'p.ma_sp', '=', 'h.ma_sp')
            ->where('h.id_cua_hang', $branchId)
            ->when(isset($filters['product_id']), fn ($query) => $query->where('h.ma_sp', $filters['product_id']))
            ->orderByDesc('h.created_at')
            ->orderByDesc('h.id')
            ->select(['h.id', 'h.ma_sp', 'p.ten_sp', 'h.ton_cu', 'h.ton_moi', 'h.tk_admin', 'h.created_at'])
            ->paginate($filters['per_page'] ?? 20);
    }

    public function orders(int|string $branchId, bool $employee, string $adminId, array $filters): LengthAwarePaginator
    {
        return DB::table('don_hang as o')
            ->leftJoin('nguoi_dung as u', 'u.ma_kh', '=', 'o.ma_kh')
            ->whereIn('o.trang_thai', ['Cho xac nhan', 'Dang Giao'])
            ->where(function ($query) use ($branchId): void {
                $query->whereNull('o.id_cua_hang')->orWhere('o.id_cua_hang', $branchId);
            })
            ->when($employee, function ($query) use ($adminId): void {
                $query->where(function ($assigned) use ($adminId): void {
                    $assigned->whereNull('o.nhan_vien_id')->orWhere('o.nhan_vien_id', $adminId);
                });
            })
            ->when(isset($filters['status']), fn ($query) => $query->where('o.trang_thai', $filters['status']))
            ->select([
                'o.ma_dh',
                'o.ngay_dat',
                'o.tong_tien',
                'o.trang_thai',
                'o.id_cua_hang',
                'o.nhan_vien_id',
                'o.ten_nguoi_nhan',
                'o.dien_thoai_nguoi_nhan',
                'o.dia_chi_giao_hang',
                'u.email as customer_email',
            ])
            ->orderBy('o.ngay_dat')
            ->paginate($filters['per_page'] ?? 20);
    }

    public function claimOrder(string $orderId, int|string $branchId, string $adminId, ?string $ipAddress): array
    {
        return DB::transaction(function () use ($orderId, $branchId, $adminId, $ipAddress): array {
            $order = DB::table('don_hang')->where('ma_dh', $orderId)->lockForUpdate()->first();
            if (! $order) {
                return ['not_found' => true];
            }
            if (! in_array($order->trang_thai, ['Cho xac nhan', 'Dang Giao'], true)) {
                return ['conflict' => true];
            }
            if (($order->id_cua_hang && (int) $order->id_cua_hang !== (int) $branchId)
                || ($order->nhan_vien_id && $order->nhan_vien_id !== $adminId)) {
                return ['conflict' => true];
            }

            DB::table('don_hang')->where('ma_dh', $orderId)->update([
                'id_cua_hang' => $branchId,
                'nhan_vien_id' => $adminId,
            ]);
            DB::table('nhat_ky_hoat_dong')->insert([
                'tk_admin' => $adminId,
                'hanh_dong' => 'order.claimed',
                'chi_tiet' => $orderId,
                'dia_chi_ip' => $ipAddress,
                'thoi_gian_tao' => now(),
            ]);

            return ['claimed' => true];
        });
    }

    public function payroll(string $adminId): Collection
    {
        return DB::table('bang_luong')
            ->where('tk_admin', $adminId)
            ->orderByDesc('nam')
            ->orderByDesc('thang')
            ->limit(24)
            ->get([
                'thang',
                'nam',
                'luong_co_ban',
                'phu_cap',
                'thuong',
                'khau_tru',
                'trang_thai',
                'ngay_thanh_toan',
            ]);
    }

    public function updateWarranty(
        string $serialNumber,
        array $data,
        string $adminId,
        ?string $ipAddress,
        bool $branchManager,
        int|string|null $branchId,
        CustomerNotificationService $notifications,
    ): array {
        $legacySerials = Schema::hasColumn('so_serial_san_pham', 'id_serial');
        if ($branchManager && ! Schema::hasColumn('so_serial_san_pham', 'id_cua_hang')) {
            return ['scope_unavailable' => true];
        }
        $serialId = $legacySerials ? 'id_serial' : 'id';
        $serialName = $legacySerials ? 'so_serial' : 'serial_number';
        $warrantySerialId = $legacySerials ? 'id_serial' : 'product_serial_id';

        $warranty = DB::table('bao_hanh as w')
            ->join('so_serial_san_pham as s', "s.{$serialId}", '=', "w.{$warrantySerialId}")
            ->where("s.{$serialName}", $serialNumber)
            ->when(
                $branchManager,
                fn ($query) => $query->where('s.id_cua_hang', $branchId),
            )
            ->select("w.{$warrantySerialId} as serial_id", 'w.ghi_chu_xu_ly', 'w.ma_kh', 'w.ma_dh')
            ->first();
        if (! $warranty) {
            return ['not_found' => true];
        }

        $updates = ['trang_thai_xu_ly' => $data['status']];
        if (array_key_exists('note', $data)) {
            $updates['ghi_chu_xu_ly'] = $data['note'];
        }
        DB::table('bao_hanh')->where($warrantySerialId, $warranty->serial_id)->update($updates);
        DB::table('nhat_ky_hoat_dong')->insert([
            'tk_admin' => $adminId,
            'hanh_dong' => 'warranty.updated',
            'chi_tiet' => "{$serialNumber}: {$data['status']}",
            'dia_chi_ip' => $ipAddress,
            'thoi_gian_tao' => now(),
        ]);
        $notifications->create(
            $warranty->ma_kh,
            'warranty.updated',
            'Cập nhật bảo hành',
            "Yêu cầu bảo hành mã {$serialNumber} đã được cập nhật trạng thái: {$data['status']}.",
            $warranty->ma_dh,
        );

        return ['updated' => true];
    }
}
