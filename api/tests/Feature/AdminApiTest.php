<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_edit_publish_and_remove_unordered_products(): void
    {
        $adminToken = $this->createAdminAndLogin();
        $this->getJson('/api/v1/admin/products')->assertUnauthorized();
        $this->withToken($this->registerCustomer())
            ->getJson('/api/v1/admin/products')
            ->assertForbidden();

        $created = $this->withToken($adminToken)
            ->postJson('/api/v1/admin/products', [
                'name' => 'Laptop Admin Test',
                'category' => 'Laptop',
                'brand' => 'TechStore',
                'description' => 'Test description',
                'price' => 15000000,
                'list_price' => 17000000,
                'stock' => 3,
                'status' => 'draft',
                'specifications' => ['cpu' => 'Intel Core i7', 'ram' => '16 GB DDR5'],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withToken($adminToken)
            ->patchJson("/api/v1/admin/products/{$created}", [
                'status' => 'published',
                'image_url' => 'https://example.test/laptop.jpg',
            ])
            ->assertOk();
        $this->getJson('/api/v1/products?category=Laptop')
            ->assertOk()
            ->assertJsonPath('data.0.id', $created);

        $this->withToken($adminToken)
            ->getJson('/api/v1/admin/products?status=published')
            ->assertOk()
            ->assertJsonPath('data.0.ma_sp', $created);
        $this->withToken($adminToken)
            ->deleteJson("/api/v1/admin/products/{$created}")
            ->assertOk();

        $this->assertDatabaseHas('nhat_ky_hoat_dong', [
            'hanh_dong' => 'product.created',
            'tk_admin' => 'admin01',
        ]);
    }

    public function test_admin_can_mark_a_product_as_used_and_remove_it_from_the_storefront(): void
    {
        $adminToken = $this->createAdminAndLogin();
        $created = $this->withToken($adminToken)
            ->postJson('/api/v1/admin/products', [
                'name' => 'Laptop Gaming Acer Nitro - Cũ Trầy Xước',
                'category' => 'Laptop',
                'brand' => 'Acer',
                'price' => 15000000,
                'stock' => 2,
                'status' => 'published',
                'is_used' => true,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->getJson('/api/v1/products?category=Laptop')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/products/{$created}")->assertNotFound();
        $this->withToken($adminToken)->getJson('/api/v1/admin/products?status=published')
            ->assertOk()
            ->assertJsonPath('data.0.la_hang_cu', true);

        $this->withToken($adminToken)->patchJson("/api/v1/admin/products/{$created}", ['is_used' => false])
            ->assertOk();
        $this->getJson('/api/v1/products?category=Laptop')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $created);
    }

    public function test_admin_must_confirm_mock_transfer_before_shipping_an_order(): void
    {
        $customerToken = $this->registerCustomer();
        $adminToken = $this->createAdminAndLogin();
        DB::table('san_pham')->insert([
            'ma_sp' => 'LAPTOP0001',
            'ten_sp' => 'Laptop Test',
            'loai' => 'Laptop',
            'thuong_hieu' => 'Brand Test',
            'gia_ban' => 15000000,
            'so_luong' => 2,
            'stt' => 0,
            'slug' => 'laptop-test',
            'trang_thai_hien_thi' => 'published',
        ]);

        $this->withToken($customerToken)
            ->postJson('/api/v1/cart/items', ['product_id' => 'LAPTOP0001'])
            ->assertCreated();
        $order = $this->withToken($customerToken)
            ->withHeader('Idempotency-Key', '045a7dc7-7f36-44a7-8eb9-15eae4a1d403')
            ->postJson('/api/v1/orders', [
                'recipient_name' => 'Test Customer',
                'phone' => '0901234567',
                'address' => '1 Test Street',
                'district' => 'Quan 1',
                'city' => 'TP Ho Chi Minh',
                'payment_method' => 'transfer',
            ])
            ->assertCreated();
        $orderId = $order->json('data.id');

        $this->withToken($adminToken)
            ->patchJson("/api/v1/admin/orders/{$orderId}", ['action' => 'status', 'status' => 'Da Giao'])
            ->assertUnprocessable();
        $this->withToken($adminToken)
            ->patchJson("/api/v1/admin/orders/{$orderId}", ['action' => 'status', 'status' => 'Dang Giao'])
            ->assertUnprocessable();
        $this->withToken($adminToken)
            ->patchJson("/api/v1/admin/orders/{$orderId}", ['action' => 'mark_paid'])
            ->assertOk();
        $this->withToken($adminToken)
            ->patchJson("/api/v1/admin/orders/{$orderId}", ['action' => 'status', 'status' => 'Dang Giao'])
            ->assertOk();
        $this->withToken($adminToken)
            ->patchJson("/api/v1/admin/orders/{$orderId}", ['action' => 'status', 'status' => 'Da Giao'])
            ->assertOk();

        $this->assertDatabaseHas('thanh_toan', [
            'ma_dh' => $orderId,
            'trang_thai' => 'Da Thanh Toan',
        ]);
        $this->assertDatabaseHas('don_hang', ['ma_dh' => $orderId, 'trang_thai' => 'Da Giao']);
        $this->assertDatabaseHas('nhat_ky_trang_thai_don', [
            'ma_dh' => $orderId,
            'trang_thai_cu' => 'Dang Giao',
            'trang_thai' => 'Da Giao',
            'nguoi_thuc_hien' => 'admin:admin01',
        ]);
    }

    public function test_momo_checkout_requires_manual_payment_confirmation_before_shipping(): void
    {
        $customerToken = $this->registerCustomer();
        $adminToken = $this->createAdminAndLogin();
        DB::table('san_pham')->insert([
            'ma_sp' => 'MOMOPHONE1',
            'ten_sp' => 'MoMo Test Phone',
            'loai' => 'Điện thoại',
            'gia_ban' => 5000000,
            'so_luong' => 1,
            'stt' => 0,
            'slug' => 'momo-test-phone',
            'trang_thai_hien_thi' => 'published',
        ]);
        $this->withToken($customerToken)->postJson('/api/v1/cart/items', ['product_id' => 'MOMOPHONE1'])->assertCreated();
        $order = $this->withToken($customerToken)
            ->withHeader('Idempotency-Key', '045a7dc7-7f36-44a7-8eb9-15eae4a1d411')
            ->postJson('/api/v1/orders', [
                'recipient_name' => 'Test Customer',
                'phone' => '0901234567',
                'address' => '1 Test Street',
                'district' => 'Quan 1',
                'city' => 'TP Ho Chi Minh',
                'payment_method' => 'momo',
            ])->assertCreated();
        $orderId = $order->json('data.id');

        $this->assertDatabaseHas('thanh_toan', ['ma_dh' => $orderId, 'phuong_thuc' => 'MoMo', 'trang_thai' => 'Chua Thanh Toan']);
        $this->withToken($adminToken)
            ->patchJson("/api/v1/admin/orders/{$orderId}", ['action' => 'status', 'status' => 'Dang Giao'])
            ->assertUnprocessable();
        $this->withToken($adminToken)
            ->patchJson("/api/v1/admin/orders/{$orderId}", ['action' => 'mark_paid'])
            ->assertOk();
        $this->withToken($adminToken)
            ->patchJson("/api/v1/admin/orders/{$orderId}", ['action' => 'status', 'status' => 'Dang Giao'])
            ->assertOk();

        $this->assertDatabaseHas('thong_bao_nguoi_dung', ['ma_dh' => $orderId, 'loai' => 'payment.confirmed']);
        $this->assertDatabaseHas('thong_bao_nguoi_dung', ['ma_dh' => $orderId, 'loai' => 'order.shipping']);
    }

    public function test_customer_can_track_delivery_and_request_a_return_within_seven_days(): void
    {
        $customerToken = $this->registerCustomer();
        $adminToken = $this->createAdminAndLogin();
        DB::table('san_pham')->insert([
            'ma_sp' => 'TRKPHONE01',
            'ten_sp' => 'Tracking Test Phone',
            'loai' => 'Điện thoại',
            'gia_ban' => 5000000,
            'so_luong' => 2,
            'stt' => 0,
            'slug' => 'tracking-test-phone',
            'trang_thai_hien_thi' => 'published',
        ]);
        $this->withToken($customerToken)->postJson('/api/v1/cart/items', ['product_id' => 'TRKPHONE01'])->assertCreated();
        $orderId = $this->withToken($customerToken)
            ->withHeader('Idempotency-Key', '045a7dc7-7f36-44a7-8eb9-15eae4a1d412')
            ->postJson('/api/v1/orders', [
                'recipient_name' => 'Test Customer',
                'phone' => '0901234567',
                'address' => '1 Test Street',
                'district' => 'Quan 1',
                'city' => 'TP Ho Chi Minh',
                'payment_method' => 'cash',
            ])->assertCreated()->json('data.id');

        $this->withToken($adminToken)
            ->patchJson("/api/v1/admin/orders/{$orderId}", ['action' => 'status', 'status' => 'Dang Giao'])
            ->assertOk();
        $this->withToken($adminToken)
            ->patchJson("/api/v1/admin/orders/{$orderId}", [
                'action' => 'tracking',
                'carrier' => 'Đơn vị vận chuyển',
                'tracking_code' => 'TRACK-12345',
                'estimated_delivery' => now()->addDay()->toDateString(),
            ])->assertOk();
        $this->withToken($customerToken)->getJson("/api/v1/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.order.ma_van_don', 'TRACK-12345')
            ->assertJsonPath('data.order.don_vi_van_chuyen', 'Đơn vị vận chuyển');

        $this->withToken($adminToken)
            ->patchJson("/api/v1/admin/orders/{$orderId}", ['action' => 'status', 'status' => 'Da Giao'])
            ->assertOk();
        $this->withToken($customerToken)->postJson("/api/v1/orders/{$orderId}/returns", [
            'type' => 'return',
            'reason' => 'damaged',
            'description' => 'Móp vỏ hộp',
        ])->assertCreated();
        $this->withToken($customerToken)->getJson('/api/v1/returns')
            ->assertOk()
            ->assertJsonPath('data.0.ma_dh', $orderId)
            ->assertJsonPath('data.0.trang_thai', 'Cho xu ly');
        $this->withToken($customerToken)->postJson("/api/v1/orders/{$orderId}/returns", [
            'type' => 'return',
            'reason' => 'other',
        ])->assertUnprocessable();
        DB::table('nhat_ky_trang_thai_don')->where('ma_dh', $orderId)->where('trang_thai', 'Da Giao')
            ->update(['thoi_gian_tao' => now()->subDays(8)]);
        $this->withToken($customerToken)->postJson("/api/v1/orders/{$orderId}/returns", [
            'type' => 'exchange',
            'reason' => 'defective',
        ])->assertUnprocessable()->assertJsonValidationErrors('order');

        $this->withToken($adminToken)->getJson('/api/v1/admin/returns')->assertOk()->assertJsonPath('data.0.ma_dh', $orderId);
        $returnId = DB::table('yeu_cau_doi_tra')->where('ma_dh', $orderId)->value('id');
        $this->withToken($adminToken)->patchJson("/api/v1/admin/returns/{$returnId}", [
            'status' => 'Approved',
            'note' => 'Đã tiếp nhận để kiểm tra.',
        ])->assertOk();
        $this->assertDatabaseHas('thong_bao_nguoi_dung', ['ma_dh' => $orderId, 'loai' => 'return.updated']);

        $this->withToken($adminToken)->getJson('/api/v1/admin/reports')
            ->assertOk()
            ->assertJsonPath('data.orders_total', 1)
            ->assertJsonPath('data.sales_total', 5000000)
            ->assertJsonPath('data.top_products.0.ma_sp', 'TRKPHONE01');
    }

    private function createAdminAndLogin(): string
    {
        DB::table('quan_tri')->insert([
            'tk_admin' => 'admin01',
            'mat_khau' => Hash::make('admin-password'),
            'ten_admin' => 'Administrator',
            'email' => 'admin@example.test',
        ]);

        return $this->postJson('/api/v1/auth/admin/login', [
            'identifier' => 'admin01',
            'password' => 'admin-password',
        ])->assertOk()->json('token');
    }

    private function registerCustomer(): string
    {
        return $this->postJson('/api/v1/auth/register', [
            'name' => 'Test Customer',
            'username' => 'customer',
            'email' => 'customer@example.test',
            'password' => 'strong-password',
            'password_confirmation' => 'strong-password',
        ])->assertCreated()->json('token');
    }
}
