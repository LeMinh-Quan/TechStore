<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminService
{
    public function productScopeError(array $account): ?string
    {
        $staff = $account['record'];

        return $staff->role === 'giam_doc_chi_nhanh' && ! $staff->id_cua_hang
            ? 'Tài khoản chưa được gán chi nhánh.'
            : null;
    }

    public function products(array $filters, array $account): LengthAwarePaginator
    {
        $staff = $account['record'];

        $products = DB::table('san_pham')->whereNull('deleted_at')
            ->when(isset($filters['q']), function ($query) use ($filters) {
                $term = '%'.addcslashes($filters['q'], '\\%_').'%';
                $query->where(fn ($search) => $search->where('ten_sp', 'like', $term)
                    ->orWhere('ma_sp', 'like', $term)->orWhere('thuong_hieu', 'like', $term));
            })
            ->when(isset($filters['status']), fn ($query) => $query->where('trang_thai_hien_thi', $filters['status']))
            ->when(isset($filters['category']), fn ($query) => $query->where('loai', $filters['category']))
            ->when($staff->role === 'giam_doc_chi_nhanh', fn ($query) => $query
                ->where('tk_admin_tao', $account['id'])->where('id_cua_hang', $staff->id_cua_hang))
            ->orderByDesc('stt')->paginate($filters['per_page'] ?? 20)->withQueryString();
        $products->setCollection($products->getCollection()->map(fn ($product) => $this->presentProduct($product)));

        return $products;
    }

    public function branches(): object
    {
        return DB::table('cua_hang')->orderBy('id_cua_hang')->get();
    }

    public function createBranch(array $data, string $adminId, ?string $ip): int|string
    {
        $id = DB::table('cua_hang')->insertGetId([
            'ten_cua_hang' => $data['name'], 'dia_chi' => $data['address'],
            'dien_thoai' => $data['phone'] ?? null, 'trang_thai' => $data['active'] ?? true,
        ], 'id_cua_hang');
        $this->audit($adminId, 'branch.created', (string) $id, $ip);

        return $id;
    }

    public function trashedProducts(): LengthAwarePaginator
    {
        $products = DB::table('san_pham')->whereNotNull('deleted_at')->orderByDesc('deleted_at')->paginate(20);
        $products->setCollection($products->getCollection()->map(fn ($product) => $this->presentProduct($product)));

        return $products;
    }

    public function createProduct(array $data, array $account, ?string $ip): array
    {
        if (($data['is_used'] ?? false) && ! Schema::hasColumn('san_pham', 'la_hang_cu')
            && preg_match('/\s-\s*cũ(?:\s|$)/iu', $data['name']) !== 1) {
            return ['status' => 503, 'message' => 'Cần chạy migration cập nhật trạng thái hàng cũ trước.'];
        }
        $staff = $account['record'];
        $branchId = $staff->role === 'giam_doc_chi_nhanh' ? $staff->id_cua_hang : null;
        if ($staff->role === 'giam_doc_chi_nhanh' && ! $branchId) {
            return ['status' => 422, 'message' => 'Tài khoản chưa được gán chi nhánh.'];
        }
        $id = Str::upper(Str::random(10));
        $slug = $this->uniqueSlug($data['name'].'-'.$id);
        $productData = [
            'ma_sp' => $id, 'ten_sp' => $data['name'], 'loai' => $data['category'],
            'thuong_hieu' => $data['brand'] ?? null, 'mo_ta' => $data['description'] ?? null,
            'gia_ban' => $data['price'], 'gia_niem_yet' => $data['list_price'] ?? null,
            'so_luong' => $data['stock'], 'stt' => 0, 'slug' => $slug,
            'trang_thai_hien_thi' => $data['status'], 'source_url' => null,
            'thong_so_ky_thuat' => json_encode($data['specifications'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'tk_admin_tao' => $account['id'], 'id_cua_hang' => $branchId,
        ];
        if (Schema::hasColumn('san_pham', 'la_hang_cu')) {
            $productData['la_hang_cu'] = $data['is_used'] ?? false;
        }
        DB::transaction(function () use ($data, $id, $productData, $branchId): void {
            DB::table('san_pham')->insert($productData);
            if ($branchId !== null) {
                DB::table('ton_kho_cua_hang')->insert(['id_cua_hang' => $branchId, 'ma_sp' => $id, 'so_luong_ton' => $data['stock']]);
            }
            $this->saveProductImage($id, $data['image_url'] ?? null, $data['name']);
        });
        $this->audit($account['id'], 'product.created', $id, $ip);

        return ['id' => $id, 'slug' => $slug];
    }

    public function updateProduct(string $productId, array $data, array $account, ?string $ip): array
    {
        $product = DB::table('san_pham')->where('ma_sp', $productId)->whereNull('deleted_at')->first();
        if (! $product || ! $this->canManageProduct($account, $product)) {
            return ['status' => 404, 'message' => 'Không tìm thấy sản phẩm.'];
        }
        if (array_key_exists('is_used', $data) && ! Schema::hasColumn('san_pham', 'la_hang_cu')
            && $data['is_used'] !== (preg_match('/\s-\s*cũ(?:\s|$)/iu', $data['name'] ?? $product->ten_sp) === 1)) {
            return ['status' => 503, 'message' => 'Cần chạy migration cập nhật trạng thái hàng cũ trước.'];
        }
        if (($account['record']->role ?? null) === 'giam_doc_chi_nhanh' && array_key_exists('stock', $data)) {
            throw ValidationException::withMessages(['stock' => ['Cập nhật tồn kho chi nhánh bằng chức năng riêng.']]);
        }
        if (array_key_exists('stock', $data) && (int) $data['stock'] !== (int) $product->so_luong
            && DB::table('so_serial_san_pham')->where('ma_sp', $productId)->exists()) {
            throw ValidationException::withMessages(['stock' => ['Điều chỉnh tồn kho sản phẩm có serial qua phiếu nhập kho; không sửa trực tiếp.']]);
        }
        $updates = [];
        foreach (['name' => 'ten_sp', 'category' => 'loai', 'brand' => 'thuong_hieu', 'description' => 'mo_ta',
            'price' => 'gia_ban', 'list_price' => 'gia_niem_yet', 'stock' => 'so_luong', 'status' => 'trang_thai_hien_thi'] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $updates[$column] = $data[$input];
            }
        }
        if (array_key_exists('is_used', $data) && Schema::hasColumn('san_pham', 'la_hang_cu')) {
            $updates['la_hang_cu'] = $data['is_used'];
        }
        if (isset($data['name'])) {
            $updates['slug'] = $this->uniqueSlug($data['name'].'-'.$productId, $productId);
        }
        if (array_key_exists('specifications', $data)) {
            $updates['thong_so_ky_thuat'] = json_encode($data['specifications'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        DB::transaction(function () use ($productId, $data, $updates, $product): void {
            if ($updates !== []) {
                DB::table('san_pham')->where('ma_sp', $productId)->update($updates);
            }
            if (array_key_exists('image_url', $data)) {
                DB::table('san_pham_hinh_anh')->where('ma_sp', $productId)->delete();
                $this->saveProductImage($productId, $data['image_url'], $data['name'] ?? $product->ten_sp);
            }
        });
        $this->audit($account['id'], 'product.updated', $productId, $ip);

        return ['message' => 'Đã cập nhật sản phẩm.'];
    }

    public function deleteProduct(string $productId, array $account, ?string $ip): array
    {
        $product = DB::table('san_pham')->where('ma_sp', $productId)->whereNull('deleted_at')->first();
        if (! $product || ! $this->canManageProduct($account, $product)) {
            return ['status' => 404, 'message' => 'Không tìm thấy sản phẩm.'];
        }
        DB::table('san_pham')->where('ma_sp', $productId)->update(['deleted_at' => now()]);
        $this->audit($account['id'], 'product.deleted', $productId, $ip);

        return ['message' => 'Đã chuyển sản phẩm vào thùng rác.'];
    }

    public function restoreProduct(string $productId, array $account, ?string $ip): array
    {
        $updated = DB::table('san_pham')->where('ma_sp', $productId)->whereNotNull('deleted_at')->update(['deleted_at' => null]);
        if (! $updated) {
            return ['status' => 404, 'message' => 'Không tìm thấy sản phẩm trong thùng rác.'];
        }
        $this->audit($account['id'], 'product.restored', $productId, $ip);

        return ['message' => 'Đã khôi phục sản phẩm.'];
    }

    public function forceDeleteProduct(string $productId, array $account, ?string $ip): array
    {
        if (! DB::table('san_pham')->where('ma_sp', $productId)->whereNotNull('deleted_at')->exists()) {
            return ['status' => 404, 'message' => 'Không tìm thấy sản phẩm trong thùng rác.'];
        }
        if (DB::table('chi_tiet_don_hang')->where('ma_sp', $productId)->exists()
            || DB::table('so_serial_san_pham')->where('ma_sp', $productId)->exists()) {
            return ['status' => 409, 'message' => 'Sản phẩm có lịch sử đơn hàng hoặc serial; không thể xóa vĩnh viễn.'];
        }
        DB::table('san_pham')->where('ma_sp', $productId)->delete();
        $this->audit($account['id'], 'product.force_deleted', $productId, $ip);

        return ['message' => 'Đã xóa vĩnh viễn sản phẩm.'];
    }

    public function vouchers(): object
    {
        if (Schema::hasColumn('phieu_giam_gia', 'ma_code')) {
            return DB::table('phieu_giam_gia')->select([
                'ma_code as code', DB::raw("CASE WHEN loai_giam = 'percentage' THEN 'percent' ELSE 'fixed' END as discount_type"),
                'gia_tri_giam as discount_value', 'gia_tri_don_toi_thieu as minimum_order_value',
                'giam_toi_da as maximum_discount', 'da_dung as usage_count', 'so_luong as usage_limit',
                'ngay_bat_dau as starts_at', 'ngay_ket_thuc as expires_at', 'trang_thai as is_active',
            ])->orderByDesc('id_voucher')->get();
        }

        return DB::table('phieu_giam_gia')->orderByDesc('created_at')->get();
    }

    public function voucherCodeColumn(): string
    {
        return Schema::hasColumn('phieu_giam_gia', 'ma_code') ? 'ma_code' : 'code';
    }

    public function createVoucher(array $data, string $adminId, ?string $ip): string
    {
        $code = $data['code'];
        if ($data['discount_type'] === 'percent' && $data['discount_value'] > 100) {
            throw ValidationException::withMessages(['discount_value' => ['Phần trăm giảm giá không được vượt quá 100.']]);
        }
        if ($this->voucherCodeColumn() === 'ma_code') {
            DB::table('phieu_giam_gia')->insert([
                'ma_code' => $code, 'loai_giam' => $data['discount_type'] === 'percent' ? 'percentage' : 'fixed_amount',
                'gia_tri_giam' => $data['discount_value'], 'gia_tri_don_toi_thieu' => $data['minimum_order_value'] ?? 0,
                'giam_toi_da' => $data['maximum_discount'] ?? null, 'so_luong' => $data['usage_limit'] ?? null,
                'da_dung' => 0, 'ngay_bat_dau' => $data['starts_at'] ?? null, 'ngay_ket_thuc' => $data['expires_at'] ?? null,
                'trang_thai' => true,
            ]);
        } else {
            DB::table('phieu_giam_gia')->insert([
                ...$data, 'minimum_order_value' => $data['minimum_order_value'] ?? 0,
                'maximum_discount' => $data['maximum_discount'] ?? null, 'usage_limit' => $data['usage_limit'] ?? null,
                'usage_count' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->audit($adminId, 'voucher.created', $code, $ip);

        return $code;
    }

    public function updateVoucher(string $code, bool $active, string $adminId, ?string $ip): array
    {
        $legacy = $this->voucherCodeColumn() === 'ma_code';
        $code = strtoupper($code);
        $query = DB::table('phieu_giam_gia')->where($legacy ? 'ma_code' : 'code', $code);
        $updated = $query->update($legacy ? ['trang_thai' => $active] : ['is_active' => $active, 'updated_at' => now()]);
        if (! $updated && ! DB::table('phieu_giam_gia')->where($legacy ? 'ma_code' : 'code', $code)->exists()) {
            return ['status' => 404, 'message' => 'Không tìm thấy voucher.'];
        }
        $this->audit($adminId, $active ? 'voucher.activated' : 'voucher.deactivated', $code, $ip);

        return ['message' => $active ? 'Đã kích hoạt voucher.' : 'Đã ngừng voucher.'];
    }

    public function stockReceipts(array $account): array
    {
        $legacy = Schema::hasColumn('phieu_nhap_kho', 'id_phieu_nhap');
        $staff = $account['record'];
        $branchManager = $staff->role === 'giam_doc_chi_nhanh';
        if ($branchManager && (! $staff->id_cua_hang || ! Schema::hasColumn('phieu_nhap_kho', 'id_cua_hang'))) {
            return ['status' => 503, 'message' => 'Không thể xác định phạm vi chi nhánh cho phiếu nhập kho.'];
        }
        $receipts = DB::table('phieu_nhap_kho as r')
            ->join('nha_cung_cap as s', $legacy ? 's.id_nha_cung_cap' : 's.id', '=', $legacy ? 'r.id_nha_cung_cap' : 'r.supplier_id')
            ->select([$legacy ? 'r.id_phieu_nhap as id' : 'r.id', 'r.reference',
                $legacy ? 'r.ngay_nhap as received_at' : 'r.received_at', $legacy ? 's.ten_nha_cung_cap as supplier' : 's.name as supplier'])
            ->when($branchManager, fn ($query) => $query->where('r.id_cua_hang', $staff->id_cua_hang))
            ->orderByDesc($legacy ? 'r.ngay_nhap' : 'r.received_at')->paginate(20);

        return ['paginator' => $receipts];
    }

    public function createStockReceipt(array $data, array $account, ?string $ip): array
    {
        $staff = $account['record'];
        $branchId = $staff->role === 'giam_doc_chi_nhanh' ? $staff->id_cua_hang : null;
        if ($staff->role === 'giam_doc_chi_nhanh' && ! $branchId) {
            return ['status' => 422, 'message' => 'Tài khoản chưa được gán chi nhánh.'];
        }
        if ($staff->role === 'giam_doc_chi_nhanh'
            && (! Schema::hasColumn('phieu_nhap_kho', 'id_cua_hang') || ! Schema::hasColumn('so_serial_san_pham', 'id_cua_hang'))) {
            return ['status' => 503, 'message' => 'Cơ sở dữ liệu chưa hỗ trợ tồn kho theo chi nhánh.'];
        }
        $id = DB::transaction(function () use ($data, $account, $branchId): int|string {
            $legacy = Schema::hasColumn('phieu_nhap_kho', 'id_phieu_nhap');
            $supplierName = trim($data['supplier']);
            $nameColumn = $legacy ? 'ten_nha_cung_cap' : 'name';
            $idColumn = $legacy ? 'id_nha_cung_cap' : 'id';
            $supplier = DB::table('nha_cung_cap')->where($nameColumn, $supplierName)->first();
            $supplierId = $supplier ? $supplier->{$idColumn} : DB::table('nha_cung_cap')->insertGetId([$nameColumn => $supplierName]);
            if ($legacy) {
                $receiptId = 'IMP'.Str::upper(Str::random(17));
                $receipt = ['id_phieu_nhap' => $receiptId, 'id_nha_cung_cap' => $supplierId, 'tk_admin' => $account['id'],
                    'tong_chi_phi' => array_sum(array_map(fn ($item) => count($item['serials']) * (int) $item['unit_cost'], $data['items'])),
                    'ngay_nhap' => now(), 'reference' => $data['reference'] ?? null];
                if (Schema::hasColumn('phieu_nhap_kho', 'id_cua_hang')) {
                    $receipt['id_cua_hang'] = $branchId;
                }
                DB::table('phieu_nhap_kho')->insert($receipt);
            } else {
                $receipt = ['supplier_id' => $supplierId, 'reference' => $data['reference'] ?? null, 'tk_admin' => $account['id'],
                    'received_at' => now(), 'created_at' => now(), 'updated_at' => now()];
                if (Schema::hasColumn('phieu_nhap_kho', 'id_cua_hang')) {
                    $receipt['id_cua_hang'] = $branchId;
                }
                $receiptId = DB::table('phieu_nhap_kho')->insertGetId($receipt);
            }
            foreach ($data['items'] as $item) {
                $quantity = count($item['serials']);
                $product = DB::table('san_pham')->where('ma_sp', $item['product_id'])->whereNull('deleted_at')->lockForUpdate()->first();
                if (! $product) {
                    throw ValidationException::withMessages(['items' => ['Không tìm thấy sản phẩm đang hoạt động trong phiếu nhập.']]);
                }
                DB::table($legacy ? 'chi_tiet_phieu_nhap' : 'chi_tiet_phieu_nhap_kho')->insert($legacy
                    ? ['id_phieu_nhap' => $receiptId, 'ma_sp' => $product->ma_sp, 'so_luong' => $quantity, 'gia_nhap' => $item['unit_cost']]
                    : ['stock_receipt_id' => $receiptId, 'ma_sp' => $product->ma_sp, 'quantity' => $quantity]);
                foreach ($item['serials'] as $serial) {
                    $serialData = $legacy
                        ? ['so_serial' => trim($serial), 'ma_sp' => $product->ma_sp, 'id_phieu_nhap' => $receiptId, 'trang_thai' => 'In_Stock', 'ngay_nhap' => now()]
                        : ['serial_number' => trim($serial), 'ma_sp' => $product->ma_sp, 'stock_receipt_id' => $receiptId, 'status' => 'In_Stock', 'created_at' => now(), 'updated_at' => now()];
                    if (Schema::hasColumn('so_serial_san_pham', 'id_cua_hang')) {
                        $serialData['id_cua_hang'] = $branchId;
                    }
                    DB::table('so_serial_san_pham')->insert($serialData);
                }
                if ($branchId !== null) {
                    $stock = DB::table('ton_kho_cua_hang')->where('id_cua_hang', $branchId)->where('ma_sp', $product->ma_sp)->lockForUpdate()->first();
                    DB::table('ton_kho_cua_hang')->updateOrInsert(['id_cua_hang' => $branchId, 'ma_sp' => $product->ma_sp],
                        ['so_luong_ton' => (int) ($stock->so_luong_ton ?? 0) + $quantity]);
                }
                DB::table('san_pham')->where('ma_sp', $product->ma_sp)->increment('so_luong', $quantity);
            }

            return $receiptId;
        });
        $this->audit($account['id'], 'inventory.received', (string) $id, $ip);

        return ['id' => $id];
    }

    public function warranties(array $account): array
    {
        $legacy = Schema::hasColumn('so_serial_san_pham', 'id_serial');
        $staff = $account['record'];
        $branchManager = $staff->role === 'giam_doc_chi_nhanh';
        if ($branchManager && (! $staff->id_cua_hang || ! Schema::hasColumn('so_serial_san_pham', 'id_cua_hang'))) {
            return ['status' => 503, 'message' => 'Không thể xác định phạm vi chi nhánh cho bảo hành.'];
        }

        return ['data' => DB::table('bao_hanh as w')
            ->join('so_serial_san_pham as s', $legacy ? 's.id_serial' : 's.id', '=', $legacy ? 'w.id_serial' : 'w.product_serial_id')
            ->join('san_pham as p', 'p.ma_sp', '=', 's.ma_sp')->join('nguoi_dung as u', 'u.ma_kh', '=', 'w.ma_kh')
            ->when($branchManager, fn ($query) => $query->where('s.id_cua_hang', $staff->id_cua_hang))
            ->select(['w.ma_dh', $legacy ? 'w.ngay_bat_dau as starts_at' : 'w.starts_at',
                $legacy ? 'w.ngay_het_han as expires_at' : 'w.expires_at', $legacy ? DB::raw("'active' as status") : 'w.status',
                'w.trang_thai_xu_ly as processing_status', 'w.ghi_chu_xu_ly as processing_note',
                $legacy ? 's.so_serial as serial_number' : 's.serial_number', 'p.ten_sp', 'u.email'])
            ->orderByDesc($legacy ? 'w.ngay_bat_dau' : 'w.starts_at')->paginate(50)->items()];
    }

    public function customers(array $filters): LengthAwarePaginator
    {
        return DB::table('nguoi_dung as u')->select(['u.ma_kh as id', 'u.ten_dang_nhap as username', 'u.ten_khach_hang as name', 'u.email'])
            ->selectSub(DB::table('don_hang')->selectRaw('COUNT(*)')->whereColumn('ma_kh', 'u.ma_kh'), 'order_count')
            ->selectSub(DB::table('gio_hang')->selectRaw('COUNT(*)')->whereColumn('ma_kh', 'u.ma_kh'), 'cart_count')
            ->when(isset($filters['q']), function ($query) use ($filters) {
                $term = '%'.addcslashes($filters['q'], '\\%_').'%';
                $query->where(fn ($search) => $search->where('u.ten_dang_nhap', 'like', $term)
                    ->orWhere('u.ten_khach_hang', 'like', $term)->orWhere('u.email', 'like', $term));
            })->orderBy('u.ma_kh')->paginate($filters['per_page'] ?? 20)->withQueryString();
    }

    public function updatePayroll(array $data, string $employeeId, int $year, int $month, array $account, ?string $ip): array
    {
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2200) {
            throw ValidationException::withMessages(['period' => ['Tháng hoặc năm bảng lương không hợp lệ.']]);
        }
        if (! DB::table('quan_tri')->where('tk_admin', $employeeId)->where('role', 'nhan_vien')->exists()) {
            return ['status' => 404, 'message' => 'Không tìm thấy nhân viên.'];
        }
        $paid = $data['paid'] ?? false;
        DB::table('bang_luong')->updateOrInsert(['tk_admin' => $employeeId, 'nam' => $year, 'thang' => $month], [
            'luong_co_ban' => $data['base_salary'], 'phu_cap' => $data['allowance'] ?? 0, 'thuong' => $data['bonus'] ?? 0,
            'khau_tru' => $data['deduction'] ?? 0, 'trang_thai' => $paid ? 'da_thanh_toan' : 'chua_thanh_toan',
            'ngay_thanh_toan' => $paid ? now() : null, 'updated_at' => now(), 'created_at' => now(),
        ]);
        $this->audit($account['id'], 'payroll.updated', "{$employeeId}:{$year}-{$month}", $ip);

        return ['message' => 'Đã lưu bảng lương nhân viên.'];
    }

    public function orders(array $filters): LengthAwarePaginator
    {
        return DB::table('don_hang as o')->leftJoin('nguoi_dung as u', 'u.ma_kh', '=', 'o.ma_kh')
            ->select(['o.ma_dh', 'o.ma_kh', 'o.ngay_dat', 'o.tong_tien', 'o.trang_thai', 'o.ten_nguoi_nhan',
                'o.dien_thoai_nguoi_nhan', 'o.dia_chi_giao_hang', 'o.don_vi_van_chuyen', 'o.ma_van_don',
                'o.ngay_giao_du_kien', 'u.email as customer_email', 'u.ten_dang_nhap as customer_username'])
            ->selectSub(DB::table('thanh_toan')->select('phuong_thuc')->whereColumn('ma_dh', 'o.ma_dh')->limit(1), 'payment_method')
            ->selectSub(DB::table('thanh_toan')->select('trang_thai')->whereColumn('ma_dh', 'o.ma_dh')->limit(1), 'payment_status')
            ->when(isset($filters['status']), fn ($query) => $query->where('o.trang_thai', $filters['status']))
            ->when(isset($filters['q']), function ($query) use ($filters) {
                $term = '%'.addcslashes($filters['q'], '\\%_').'%';
                $query->where(fn ($search) => $search->where('o.ma_dh', 'like', $term)->orWhere('u.email', 'like', $term));
            })->orderByDesc('o.ngay_dat')->paginate($filters['per_page'] ?? 20)->withQueryString();
    }

    public function showOrder(string $orderId): ?array
    {
        $order = DB::table('don_hang')->where('ma_dh', $orderId)->first();
        if (! $order) {
            return null;
        }

        return [
            'order' => $order,
            'customer' => DB::table('nguoi_dung')->where('ma_kh', $order->ma_kh)->select('ma_kh', 'ten_dang_nhap', 'ten_khach_hang', 'email')->first(),
            'payment' => DB::table('thanh_toan')->where('ma_dh', $orderId)->first(),
            'items' => DB::table('chi_tiet_don_hang')->join('san_pham', 'san_pham.ma_sp', '=', 'chi_tiet_don_hang.ma_sp')
                ->where('chi_tiet_don_hang.ma_dh', $orderId)->select('chi_tiet_don_hang.*')
                ->selectRaw('COALESCE(chi_tiet_don_hang.ten_sp_snapshot, san_pham.ten_sp) as ten_sp')->get(),
            'history' => DB::table('nhat_ky_trang_thai_don')->where('ma_dh', $orderId)->orderBy('thoi_gian_tao')->get(),
        ];
    }

    public function reports(): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $sales = DB::table('don_hang')->where('trang_thai', 'Da Giao');
        $monthlySales = (clone $sales)->whereDate('ngay_dat', '>=', $monthStart);
        $voucherUsage = DB::table('lich_su_su_dung_voucher')
            ->select('code')
            ->selectRaw('COUNT(*) as order_count, SUM(discount_amount) as total_discount')
            ->groupBy('code')
            ->orderByDesc('order_count')
            ->limit(10)
            ->get();

        return [
            'orders_total' => DB::table('don_hang')->count(),
            'orders_pending' => DB::table('don_hang')->where('trang_thai', OrderStatusService::PENDING)->count(),
            'orders_shipping' => DB::table('don_hang')->where('trang_thai', OrderStatusService::SHIPPING)->count(),
            'sales_total' => (int) $sales->sum('tong_tien'),
            'sales_month' => (int) $monthlySales->sum('tong_tien'),
            'low_stock' => DB::table('san_pham')->whereNull('deleted_at')->where('so_luong', '<=', 5)
                ->orderBy('so_luong')->limit(10)->get(['ma_sp', 'ten_sp', 'so_luong']),
            'top_products' => DB::table('chi_tiet_don_hang as i')
                ->join('don_hang as o', 'o.ma_dh', '=', 'i.ma_dh')
                ->leftJoin('san_pham as p', 'p.ma_sp', '=', 'i.ma_sp')
                ->where('o.trang_thai', OrderStatusService::DELIVERED)
                ->groupBy('i.ma_sp', 'p.ten_sp')
                ->select('i.ma_sp')
                ->selectRaw('COALESCE(MAX(i.ten_sp_snapshot), MAX(p.ten_sp)) as ten_sp')
                ->selectRaw('SUM(i.so_luong) as quantity_sold, SUM(i.so_luong * i.don_gia) as revenue')
                ->orderByDesc('quantity_sold')->limit(10)->get(),
            'voucher_effectiveness' => $voucherUsage,
        ];
    }

    public function updateOrder(
        array $data,
        string $orderId,
        array $account,
        ?string $ip,
        OrderStatusService $statuses,
        VoucherService $vouchers,
        CustomerNotificationService $notifications,
    ): array {
        return DB::transaction(function () use ($data, $orderId, $account, $ip, $statuses, $vouchers, $notifications): array {
            $order = DB::table('don_hang')->where('ma_dh', $orderId)->lockForUpdate()->first();
            if (! $order) {
                return ['not_found' => true];
            }
            if ($data['action'] === 'tracking') {
                if ($order->trang_thai !== OrderStatusService::SHIPPING) {
                    throw ValidationException::withMessages(['action' => ['Chỉ cập nhật vận đơn cho đơn đang giao.']]);
                }
                DB::table('don_hang')->where('ma_dh', $orderId)->update([
                    'don_vi_van_chuyen' => trim($data['carrier']),
                    'ma_van_don' => trim($data['tracking_code']),
                    'ngay_giao_du_kien' => $data['estimated_delivery'] ?? null,
                ]);
                $notifications->create(
                    $order->ma_kh,
                    'order.tracking',
                    'Đã cập nhật thông tin vận chuyển',
                    "Đơn hàng {$orderId}: {$data['carrier']} · Mã vận đơn {$data['tracking_code']}."
                        .(! empty($data['estimated_delivery']) ? " Dự kiến giao {$data['estimated_delivery']}." : ''),
                    $orderId,
                );
                $this->audit($account['id'], 'order.tracking_updated', $orderId, $ip);

                return ['message' => 'Đã cập nhật thông tin vận chuyển.'];
            }
            $payment = DB::table('thanh_toan')->where('ma_dh', $orderId)->lockForUpdate()->first();
            if ($data['action'] === 'mark_paid') {
                if (! $payment || ! in_array($payment->phuong_thuc, ['Chuyen khoan', 'MoMo', 'Tien mat'], true)) {
                    throw ValidationException::withMessages(['action' => ['Không tìm thấy phương thức thanh toán cần xác nhận.']]);
                }
                if ($payment->phuong_thuc === 'Tien mat' && $order->trang_thai !== 'Da Giao') {
                    throw ValidationException::withMessages(['action' => ['Chỉ xác nhận COD sau khi giao hàng thành công.']]);
                }
                if ($payment->trang_thai === 'Da Thanh Toan') {
                    return ['message' => 'Thanh toán đã được xác nhận trước đó.'];
                }
                DB::table('thanh_toan')->where('ma_dh', $orderId)->update(['trang_thai' => 'Da Thanh Toan']);
                $this->audit($account['id'], 'order.payment_confirmed', $orderId, $ip);
                $notifications->create(
                    $order->ma_kh,
                    'payment.confirmed',
                    'Đã xác nhận thanh toán',
                    "Cửa hàng đã xác nhận thanh toán cho đơn {$orderId}.",
                    $orderId,
                );

                return ['message' => 'Đã xác nhận thanh toán sau khi kiểm tra giao dịch.'];
            }
            $next = $data['status'];
            if ($next === 'Dang Giao' && in_array($payment?->phuong_thuc, ['Chuyen khoan', 'MoMo'], true) && $payment->trang_thai !== 'Da Thanh Toan') {
                throw ValidationException::withMessages(['status' => ['Cần kiểm tra và xác nhận thanh toán trước khi giao hàng.']]);
            }
            if ($next === $order->trang_thai) {
                return ['message' => 'Trạng thái đơn hàng không thay đổi.'];
            }
            if ($next === 'huy') {
                if ($payment?->trang_thai === 'Da Thanh Toan') {
                    throw ValidationException::withMessages(['status' => ['Đơn đã thanh toán; cần xử lý hoàn tiền trước khi hủy.']]);
                }
                foreach (DB::table('chi_tiet_don_hang')->where('ma_dh', $orderId)->get() as $item) {
                    DB::table('san_pham')->where('ma_sp', $item->ma_sp)->increment('so_luong', (int) $item->so_luong);
                }
                $legacy = Schema::hasColumn('so_serial_san_pham', 'id_serial');
                DB::table('so_serial_san_pham')->where('ma_dh', $orderId)->when(! $legacy, fn ($q) => $q->where('status', 'Reserved'))
                    ->update($legacy ? ['ma_dh' => null] : ['status' => 'In_Stock', 'ma_dh' => null, 'updated_at' => now()]);
                if ($order->ma_voucher) {
                    $vouchers->refund($order->ma_voucher, $order->ma_kh, $orderId);
                }
            }
            if ($next === 'Da Giao') {
                $legacy = Schema::hasColumn('so_serial_san_pham', 'id_serial');
                $serialId = $legacy ? 'id_serial' : 'id';
                $serials = DB::table('so_serial_san_pham')->where('ma_dh', $orderId)->when(! $legacy, fn ($q) => $q->where('status', 'Reserved'))
                    ->lockForUpdate()->get();
                foreach ($serials as $serial) {
                    DB::table('so_serial_san_pham')->where($serialId, $serial->{$serialId})
                        ->update($legacy ? ['trang_thai' => 'Sold'] : ['status' => 'Sold', 'updated_at' => now()]);
                    if ($legacy) {
                        if (! DB::table('bao_hanh')->where('id_serial', $serial->{$serialId})->exists()) {
                            DB::table('bao_hanh')->insert(['id_bao_hanh' => Str::upper(Str::random(20)), 'id_serial' => $serial->{$serialId},
                                'ma_kh' => $order->ma_kh, 'ma_dh' => $orderId, 'ngay_bat_dau' => now()->toDateString(),
                                'ngay_het_han' => now()->addYear()->toDateString()]);
                        }
                    } else {
                        DB::table('bao_hanh')->insertOrIgnore(['product_serial_id' => $serial->{$serialId}, 'ma_kh' => $order->ma_kh,
                            'ma_dh' => $orderId, 'starts_at' => now()->toDateString(), 'expires_at' => now()->addYear()->toDateString(),
                            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            }
            $statuses->transition($orderId, $order->trang_thai, $next, 'admin:'.$account['id'], 'Quản trị viên cập nhật trạng thái.');
            $notification = match ($next) {
                'Dang Giao' => ['order.shipping', 'Đơn hàng đang được giao', "Đơn hàng {$orderId} đã được bàn giao vận chuyển."],
                'Da Giao' => ['order.delivered', 'Đơn hàng đã giao', "Đơn hàng {$orderId} đã được giao thành công."],
                'huy' => ['order.cancelled', 'Đơn hàng đã bị hủy', "Cửa hàng đã hủy đơn {$orderId}."],
                default => ['order.updated', 'Đơn hàng đã cập nhật', "Đơn hàng {$orderId} đã được cửa hàng cập nhật."],
            };
            $notifications->create($order->ma_kh, $notification[0], $notification[1], $notification[2], $orderId);
            $this->audit($account['id'], 'order.status_updated', $orderId, $ip);

            return ['message' => 'Đã cập nhật trạng thái đơn hàng.'];
        });
    }

    private function presentProduct(object $product): array
    {
        if (property_exists($product, 'la_hang_cu')) {
            $product->la_hang_cu = (bool) $product->la_hang_cu;
        }
        $product->specifications = json_decode($product->thong_so_ky_thuat ?? '[]', true) ?: [];
        $product->images = DB::table('san_pham_hinh_anh')->where('ma_sp', $product->ma_sp)->orderBy('thu_tu')->pluck('duong_dan_anh')->all();

        return (array) $product;
    }

    private function uniqueSlug(string $source, ?string $ignoreId = null): string
    {
        $base = Str::limit(Str::slug($source), 220, '');
        $slug = $base;
        $suffix = 1;
        while (DB::table('san_pham')->where('slug', $slug)->when($ignoreId, fn ($query) => $query->where('ma_sp', '!=', $ignoreId))->exists()) {
            $slug = Str::limit($base, 230, '').'-'.$suffix++;
        }

        return $slug;
    }

    private function saveProductImage(string $id, ?string $url, string $name): void
    {
        if (! $url) {
            return;
        }
        DB::table('san_pham_hinh_anh')->insert(['ma_sp' => $id, 'duong_dan_anh' => $url, 'alt_text' => mb_substr($name, 0, 255),
            'thu_tu' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function canManageProduct(array $account, object $product): bool
    {
        $staff = $account['record'] ?? null;

        return $staff && ($staff->role !== 'giam_doc_chi_nhanh'
            || ($product->tk_admin_tao === $account['id'] && $product->id_cua_hang !== null
                && (int) $product->id_cua_hang === (int) $staff->id_cua_hang));
    }

    private function audit(string $adminId, string $action, string $details, ?string $ip): void
    {
        DB::table('nhat_ky_hoat_dong')->insert(['tk_admin' => $adminId, 'hanh_dong' => $action, 'chi_tiet' => $details,
            'dia_chi_ip' => $ip, 'thoi_gian_tao' => now()]);
    }
}
