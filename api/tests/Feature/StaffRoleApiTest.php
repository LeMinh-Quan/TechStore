<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffRoleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_roles_are_separated_and_branch_product_stock_is_scoped(): void
    {
        $branchId = $this->createBranch('Chi nhanh 1');
        $otherBranchId = $this->createBranch('Chi nhanh 2');
        $this->createInternalAccount('manager01', 'giam_doc_chi_nhanh', $branchId);
        $this->createInternalAccount('manager02', 'giam_doc_chi_nhanh', $otherBranchId);
        $this->createInternalAccount('staff01', 'nhan_vien', $branchId);
        $managerToken = $this->loginStaff('manager01');
        $otherManagerToken = $this->loginStaff('manager02');
        $staffToken = $this->loginStaff('staff01');

        $created = $this->withToken($managerToken)->postJson('/api/v1/staff/products', [
            'name' => 'Branch Product',
            'category' => 'Laptop',
            'price' => 10000000,
            'stock' => 3,
            'status' => 'draft',
        ])->assertCreated();
        $productId = $created->json('data.id');

        $this->assertDatabaseHas('san_pham', [
            'ma_sp' => $productId,
            'tk_admin_tao' => 'manager01',
            'id_cua_hang' => $branchId,
        ]);
        $this->assertDatabaseHas('ton_kho_cua_hang', [
            'id_cua_hang' => $branchId,
            'ma_sp' => $productId,
            'so_luong_ton' => 3,
        ]);
        $this->withToken($managerToken)
            ->getJson('/api/v1/staff/products')
            ->assertOk()
            ->assertJsonPath('data.0.ma_sp', $productId);

        $this->withToken($otherManagerToken)
            ->patchJson("/api/v1/staff/products/{$productId}", ['price' => 11000000])
            ->assertNotFound();
        $this->withToken($staffToken)
            ->postJson('/api/v1/staff/products', [
                'name' => 'Unauthorized Product',
                'category' => 'Laptop',
                'price' => 10000000,
                'stock' => 1,
                'status' => 'draft',
            ])
            ->assertForbidden();
        $this->withToken($staffToken)
            ->patchJson("/api/v1/staff/products/{$productId}/stock", ['stock' => 5])
            ->assertOk()
            ->assertJsonPath('data.total_stock', 5);
        $this->withToken($staffToken)
            ->getJson('/api/v1/staff/inventory/history')
            ->assertOk()
            ->assertJsonPath('data.0.ton_cu', 3)
            ->assertJsonPath('data.0.ton_moi', 5);

        $this->withToken($managerToken)->getJson('/api/v1/admin/products')->assertForbidden();
        $this->postJson('/api/v1/auth/staff/login', [
            'identifier' => 'staff01',
            'password' => 'staff-password',
        ])->assertOk()->assertJsonPath('data.role', 'nhan_vien');
        $this->postJson('/api/v1/auth/admin/login', [
            'identifier' => 'staff01',
            'password' => 'staff-password',
        ])->assertUnprocessable();
    }

    public function test_revenue_and_payroll_are_limited_to_the_account_scope(): void
    {
        $branchId = $this->createBranch('Chi nhanh doanh thu');
        $otherBranchId = $this->createBranch('Chi nhanh khac');
        $this->createInternalAccount('manager01', 'giam_doc_chi_nhanh', $branchId);
        $this->createInternalAccount('manager02', 'giam_doc_chi_nhanh', $otherBranchId);
        $this->createInternalAccount('staff01', 'nhan_vien', $branchId);
        $this->createInternalAccount('admin01', 'chu_quan');
        $this->insertDeliveredOrder('ORDER0001', 120000, $branchId, 'staff01');
        $this->insertDeliveredOrder('ORDER0002', 80000, $otherBranchId, 'manager02');
        DB::table('don_hang')->insert([
            'ma_dh' => 'ORDER0003',
            'ngay_dat' => now()->toDateString(),
            'tong_tien' => 40000,
            'trang_thai' => 'Cho xac nhan',
        ]);

        $managerToken = $this->loginStaff('manager01');
        $staffToken = $this->loginStaff('staff01');
        $this->withToken($managerToken)
            ->getJson('/api/v1/staff/revenue')
            ->assertOk()
            ->assertJsonPath('branch_revenue', 120000);
        $this->withToken($staffToken)
            ->getJson('/api/v1/staff/revenue')
            ->assertOk()
            ->assertJsonPath('personal_kpi', 120000);
        $this->withToken($staffToken)->postJson('/api/v1/staff/orders/ORDER0001/claim')->assertStatus(409);
        $this->withToken($staffToken)->getJson('/api/v1/staff/orders')->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($staffToken)->postJson('/api/v1/staff/orders/ORDER0003/claim')->assertOk();

        $adminToken = $this->loginStaff('admin01');
        $this->withToken($adminToken)->patchJson('/api/v1/admin/orders/ORDER0003', [
            'action' => 'status',
            'status' => 'Dang Giao',
        ])->assertOk();
        $this->withToken($adminToken)->patchJson('/api/v1/admin/orders/ORDER0003', [
            'action' => 'status',
            'status' => 'Da Giao',
        ])->assertOk();
        $this->withToken($staffToken)
            ->getJson('/api/v1/staff/revenue')
            ->assertOk()
            ->assertJsonPath('personal_kpi', 160000);
        $this->withToken($adminToken)->putJson('/api/v1/admin/payroll/staff01/'.now()->year.'/'.now()->month, [
            'base_salary' => 12000000,
            'allowance' => 500000,
            'paid' => true,
        ])->assertOk();
        $this->withToken($staffToken)
            ->getJson('/api/v1/staff/payroll')
            ->assertOk()
            ->assertJsonPath('data.0.luong_co_ban', 12000000);
        $this->withToken($managerToken)->getJson('/api/v1/staff/payroll')->assertForbidden();
    }

    public function test_branch_managers_receive_stock_and_process_only_their_branch_warranties(): void
    {
        $branchId = $this->createBranch('Chi nhanh bao hanh');
        $otherBranchId = $this->createBranch('Chi nhanh khac');
        $this->createInternalAccount('manager01', 'giam_doc_chi_nhanh', $branchId);
        $this->createInternalAccount('manager02', 'giam_doc_chi_nhanh', $otherBranchId);
        $managerToken = $this->loginStaff('manager01');
        $otherManagerToken = $this->loginStaff('manager02');
        $productId = $this->withToken($managerToken)->postJson('/api/v1/staff/products', [
            'name' => 'Warranty Product',
            'category' => 'Laptop',
            'price' => 10000000,
            'stock' => 0,
            'status' => 'published',
        ])->assertCreated()->json('data.id');

        $this->withToken($managerToken)->postJson('/api/v1/staff/stock-receipts', [
            'supplier' => 'Supplier Test',
            'reference' => 'BRANCH-PO-001',
            'items' => [[
                'product_id' => $productId,
                'unit_cost' => 8000000,
                'serials' => ['BRANCH-SERIAL-001'],
            ]],
        ])->assertCreated();
        $this->assertDatabaseHas('so_serial_san_pham', [
            'serial_number' => 'BRANCH-SERIAL-001',
            'id_cua_hang' => $branchId,
        ]);
        $this->assertDatabaseHas('ton_kho_cua_hang', [
            'id_cua_hang' => $branchId,
            'ma_sp' => $productId,
            'so_luong_ton' => 1,
        ]);

        $customer = $this->postJson('/api/v1/auth/register', [
            'name' => 'Warranty Customer',
            'username' => 'warrantycust',
            'email' => 'warranty@example.test',
            'password' => 'strong-password',
            'password_confirmation' => 'strong-password',
        ])->assertCreated()->json('data.id');
        DB::table('don_hang')->insert([
            'ma_dh' => 'ORDER0004',
            'ma_kh' => $customer,
            'ngay_dat' => now()->toDateString(),
            'tong_tien' => 10000000,
            'trang_thai' => 'Da Giao',
        ]);
        $serialId = DB::table('so_serial_san_pham')->where('serial_number', 'BRANCH-SERIAL-001')->value('id');
        DB::table('bao_hanh')->insert([
            'product_serial_id' => $serialId,
            'ma_kh' => $customer,
            'ma_dh' => 'ORDER0004',
            'starts_at' => now()->toDateString(),
            'expires_at' => now()->addYear()->toDateString(),
            'status' => 'active',
            'trang_thai_xu_ly' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($otherManagerToken)
            ->patchJson('/api/v1/staff/warranties/BRANCH-SERIAL-001', ['status' => 'in_repair'])
            ->assertNotFound();
        $this->withToken($managerToken)
            ->patchJson('/api/v1/staff/warranties/BRANCH-SERIAL-001', [
                'status' => 'in_repair',
                'note' => 'Đang kiểm tra sản phẩm.',
            ])
            ->assertOk();
        $this->assertDatabaseHas('bao_hanh', [
            'product_serial_id' => $serialId,
            'trang_thai_xu_ly' => 'in_repair',
            'ghi_chu_xu_ly' => 'Đang kiểm tra sản phẩm.',
        ]);
        $this->assertDatabaseHas('thong_bao_nguoi_dung', [
            'ma_kh' => $customer,
            'ma_dh' => 'ORDER0004',
            'loai' => 'warranty.updated',
        ]);
    }

    private function createBranch(string $name): int
    {
        return DB::table('cua_hang')->insertGetId([
            'ten_cua_hang' => $name,
            'dia_chi' => 'Dia chi test',
            'trang_thai' => true,
        ], 'id_cua_hang');
    }

    private function createInternalAccount(string $id, string $role, ?int $branchId = null): void
    {
        DB::table('quan_tri')->insert([
            'tk_admin' => $id,
            'mat_khau' => Hash::make($id === 'admin01' ? 'admin-password' : 'staff-password'),
            'ten_admin' => $id,
            'email' => "{$id}@example.test",
            'role' => $role,
            'id_cua_hang' => $branchId,
        ]);
    }

    private function loginStaff(string $id): string
    {
        return $this->postJson('/api/v1/auth/staff/login', [
            'identifier' => $id,
            'password' => $id === 'admin01' ? 'admin-password' : 'staff-password',
        ])->assertOk()->json('token');
    }

    private function insertDeliveredOrder(string $id, int $total, int $branchId, string $employeeId): void
    {
        DB::table('don_hang')->insert([
            'ma_dh' => $id,
            'ngay_dat' => now()->toDateString(),
            'tong_tien' => $total,
            'trang_thai' => 'Da Giao',
            'id_cua_hang' => $branchId,
            'nhan_vien_id' => $employeeId,
        ]);
    }
}
