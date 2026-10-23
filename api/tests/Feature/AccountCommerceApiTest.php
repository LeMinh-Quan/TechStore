<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountCommerceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register_view_profile_and_revoke_their_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Nguyen Van A',
            'username' => 'nguyenvana',
            'email' => 'a@example.test',
            'password' => 'strong-password',
            'password_confirmation' => 'strong-password',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Nguyen Van A');

        $token = $response->json('token');
        $this->assertDatabaseHas('api_access_tokens', [
            'token_hash' => hash('sha256', $token),
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'a@example.test');

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseMissing('api_access_tokens', [
            'token_hash' => hash('sha256', $token),
        ]);
        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_customer_can_add_to_cart_checkout_and_cancel_order(): void
    {
        $token = $this->registerCustomer();
        $this->insertPublishedProduct('LAPTOP0001', 15000000, 3);

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'product_id' => 'LAPTOP0001',
                'quantity' => 2,
                'price' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.total', 30000000);

        $checkout = $this->withToken($token)
            ->withHeader('Idempotency-Key', '045a7dc7-7f36-44a7-8eb9-15eae4a1d401')
            ->postJson('/api/v1/orders', [
                'recipient_name' => 'Nguyen Van A',
                'phone' => '0901234567',
                'address' => '12 Nguyen Hue',
                'ward' => 'Ben Nghe',
                'district' => 'Quan 1',
                'city' => 'TP Ho Chi Minh',
                'payment_method' => 'transfer',
            ])
            ->assertCreated()
            ->assertJsonPath('data.total', 30000000);

        $orderId = $checkout->json('data.id');
        $this->assertDatabaseHas('san_pham', ['ma_sp' => 'LAPTOP0001', 'so_luong' => 1]);
        $this->assertDatabaseHas('don_hang', ['ma_dh' => $orderId, 'tong_tien' => 30000000]);
        $this->assertDatabaseHas('thong_bao_nguoi_dung', [
            'ma_kh' => DB::table('nguoi_dung')->where('email', 'customer@example.test')->value('ma_kh'),
            'loai' => 'order.created',
            'ma_dh' => $orderId,
        ]);
        $this->assertDatabaseHas('nhat_ky_trang_thai_don', [
            'ma_dh' => $orderId,
            'trang_thai' => 'Cho xac nhan',
        ]);
        DB::table('san_pham')->where('ma_sp', 'LAPTOP0001')->update(['ten_sp' => 'Renamed Laptop']);
        $this->withToken($token)->getJson("/api/v1/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.items.0.ten_sp', 'Laptop Test');
        $this->withToken($token)->getJson('/api/v1/cart')->assertJsonPath('data.total', 0);
        $this->withToken($token)->getJson('/api/v1/orders')->assertJsonPath('data.0.ma_dh', $orderId);

        $this->withToken($token)
            ->postJson("/api/v1/orders/{$orderId}/cancel")
            ->assertOk();

        $this->assertDatabaseHas('san_pham', ['ma_sp' => 'LAPTOP0001', 'so_luong' => 3]);
        $this->assertDatabaseHas('don_hang', ['ma_dh' => $orderId, 'trang_thai' => 'huy']);
        $this->assertDatabaseHas('thong_bao_nguoi_dung', [
            'ma_dh' => $orderId,
            'loai' => 'order.cancelled',
        ]);
    }

    public function test_customer_notifications_are_private_and_can_be_marked_read(): void
    {
        $customerToken = $this->registerCustomer();
        $otherToken = $this->registerCustomer('other', 'other@example.test');
        $customerId = DB::table('nguoi_dung')->where('email', 'customer@example.test')->value('ma_kh');
        $notificationId = DB::table('thong_bao_nguoi_dung')->insertGetId([
            'ma_kh' => $customerId,
            'loai' => 'order.updated',
            'tieu_de' => 'Đơn hàng đang giao',
            'noi_dung' => 'Đơn hàng đã được bàn giao vận chuyển.',
            'created_at' => now(),
        ]);

        $this->withToken($customerToken)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.tieu_de', 'Đơn hàng đang giao');
        $this->withToken($otherToken)
            ->patchJson("/api/v1/notifications/{$notificationId}/read")
            ->assertNotFound();
        $this->withToken($customerToken)
            ->patchJson("/api/v1/notifications/{$notificationId}/read")
            ->assertOk();

        $this->assertDatabaseHas('thong_bao_nguoi_dung', [
            'id' => $notificationId,
            'ma_kh' => $customerId,
        ]);
        $this->assertNotNull(DB::table('thong_bao_nguoi_dung')->where('id', $notificationId)->value('read_at'));
    }

    public function test_customer_cannot_access_another_customers_order_or_admin_login(): void
    {
        $ownerToken = $this->registerCustomer('owner', 'owner@example.test');
        $otherToken = $this->registerCustomer('other', 'other@example.test');
        $this->insertPublishedProduct('LAPTOP0001', 15000000, 1);

        $this->withToken($ownerToken)
            ->postJson('/api/v1/cart/items', ['product_id' => 'LAPTOP0001'])
            ->assertCreated();

        $checkout = $this->withToken($ownerToken)
            ->withHeader('Idempotency-Key', '045a7dc7-7f36-44a7-8eb9-15eae4a1d402')
            ->postJson('/api/v1/orders', [
                'recipient_name' => 'Owner',
                'phone' => '0901234567',
                'address' => '12 Nguyen Hue',
                'district' => 'Quan 1',
                'city' => 'TP Ho Chi Minh',
                'payment_method' => 'cash',
            ])
            ->assertCreated();
        $orderId = $checkout->json('data.id');

        $this->withToken($otherToken)
            ->getJson("/api/v1/orders/{$orderId}")
            ->assertNotFound();
        $this->withToken($otherToken)
            ->postJson("/api/v1/orders/{$orderId}/cancel")
            ->assertNotFound();
    }

    public function test_invalid_login_and_missing_or_admin_tokens_are_rejected_from_customer_routes(): void
    {
        DB::table('quan_tri')->insert([
            'tk_admin' => 'admin01',
            'mat_khau' => Hash::make('admin-password'),
            'ten_admin' => 'Administrator',
            'email' => 'admin@example.test',
        ]);

        $adminLogin = $this->postJson('/api/v1/auth/admin/login', [
            'identifier' => 'admin01',
            'password' => 'admin-password',
        ])->assertOk();
        $adminToken = $adminLogin->json('token');

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'admin01',
            'password' => 'incorrect-password',
        ])->assertUnprocessable();

        $this->getJson('/api/v1/cart')->assertUnauthorized();

        $this->withToken($adminToken)
            ->getJson('/api/v1/cart')
            ->assertForbidden();

        $this->withToken($adminToken)
            ->postJson('/api/v1/auth/admin/logout')
            ->assertOk();
        $this->assertDatabaseMissing('api_access_tokens', [
            'token_hash' => hash('sha256', $adminToken),
        ]);
    }

    private function registerCustomer(string $username = 'customer', string $email = 'customer@example.test'): string
    {
        return $this->postJson('/api/v1/auth/register', [
            'name' => 'Test Customer',
            'username' => $username,
            'email' => $email,
            'password' => 'strong-password',
            'password_confirmation' => 'strong-password',
        ])->assertCreated()->json('token');
    }

    private function insertPublishedProduct(string $id, int $price, int $stock): void
    {
        DB::table('san_pham')->insert([
            'ma_sp' => $id,
            'ten_sp' => 'Laptop Test',
            'loai' => 'Laptop',
            'thuong_hieu' => 'Brand Test',
            'gia_ban' => $price,
            'so_luong' => $stock,
            'stt' => 0,
            'slug' => strtolower($id),
            'trang_thai_hien_thi' => 'published',
        ]);
    }
}
