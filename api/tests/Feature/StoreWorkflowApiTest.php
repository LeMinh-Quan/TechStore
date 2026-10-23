<?php

namespace Tests\Feature;

use App\Mail\EducationDiscountVerification;
use App\Mail\WelcomeCustomer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreWorkflowApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_queues_welcome_email_and_customer_can_manage_addresses(): void
    {
        Mail::fake();
        $registration = $this->registerCustomer();
        Mail::assertQueued(WelcomeCustomer::class);

        $token = $registration['token'];
        $this->withToken($token)->postJson('/api/v1/addresses', [
            'label' => 'Nha',
            'recipient_name' => 'Test Customer',
            'phone' => '0901234567',
            'address' => '1 Test Street',
            'ward' => 'Ben Nghe',
            'district' => 'Quan 1',
            'city' => 'TP Ho Chi Minh',
        ])->assertCreated();

        $this->withToken($token)->getJson('/api/v1/addresses')
            ->assertOk()
            ->assertJsonPath('data.0.ten_nguoi_nhan', 'Test Customer');
        $this->withToken($token)->deleteJson('/api/v1/addresses/Nha')->assertOk();
        $this->assertDatabaseMissing('dia_chi', ['ma_kh' => $registration['id'], 'loai_dia_chi' => 'Nha']);
    }

    public function test_voucher_is_validated_and_redeemed_atomically_during_checkout(): void
    {
        $token = $this->registerCustomer()['token'];
        $this->insertPublishedProduct('LAPTOP0001', 15000000, 2);
        $admin = $this->createAdminAndLogin();
        $this->withToken($admin)->postJson('/api/v1/admin/vouchers', [
            'code' => 'LAPTOP2026',
            'discount_type' => 'fixed',
            'discount_value' => 1000000,
            'minimum_order_value' => 10000000,
            'usage_limit' => 1,
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/vouchers/LAPTOP2026/claim')->assertOk();
        $this->withToken($token)->postJson('/api/v1/cart/items', ['product_id' => 'LAPTOP0001'])->assertCreated();

        $this->withToken($token)->postJson('/api/v1/vouchers/validate', ['code' => 'laptop2026'])
            ->assertOk()
            ->assertJsonPath('data.discount', 1000000)
            ->assertJsonPath('data.total', 14000000);

        $order = $this->withToken($token)->withHeader('Idempotency-Key', '045a7dc7-7f36-44a7-8eb9-15eae4a1d404')->postJson('/api/v1/orders', [
            'recipient_name' => 'Test Customer',
            'phone' => '0901234567',
            'address' => '1 Test Street',
            'district' => 'Quan 1',
            'city' => 'TP Ho Chi Minh',
            'payment_method' => 'cash',
            'voucher_code' => 'LAPTOP2026',
        ])->assertCreated()->assertJsonPath('data.total', 14000000);

        $this->assertDatabaseHas('don_hang', [
            'ma_dh' => $order->json('data.id'),
            'ma_voucher' => 'LAPTOP2026',
            'tien_giam' => 1000000,
        ]);
        $this->assertDatabaseHas('phieu_giam_gia', ['code' => 'LAPTOP2026', 'usage_count' => 1]);
    }

    public function test_customer_can_collect_a_public_voucher_before_using_it(): void
    {
        $token = $this->registerCustomer()['token'];
        $admin = $this->createAdminAndLogin();
        $this->withToken($admin)->postJson('/api/v1/admin/vouchers', [
            'code' => 'COLLECT-ME',
            'discount_type' => 'percent',
            'discount_value' => 10,
        ])->assertCreated();

        $this->withToken($token)->getJson('/api/v1/vouchers')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'COLLECT-ME')
            ->assertJsonPath('data.0.claimed', false);
        $this->withToken($token)->postJson('/api/v1/vouchers/COLLECT-ME/claim')
            ->assertOk()
            ->assertJsonPath('message', 'Đã thu thập mã giảm giá.');
        $this->assertDatabaseHas('voucher_da_thu_thap', [
            'ma_kh' => DB::table('nguoi_dung')->where('email', 'customer@example.test')->value('ma_kh'),
            'code' => 'COLLECT-ME',
        ]);
    }

    public function test_education_discount_requires_evidence_and_admin_approval_after_email_verification(): void
    {
        config()->set('services.education_discount.email_domains', ['school.edu.vn']);
        Mail::fake();
        Storage::fake('local');
        $customer = $this->registerCustomer();

        $this->withToken($customer['token'])->postJson('/api/v1/education-discount/request-code', [
            'email' => 'student@school.edu.vn',
            'audience' => 'student_university',
        ])->assertOk();

        $verificationCode = null;
        Mail::assertSent(EducationDiscountVerification::class, function (EducationDiscountVerification $mail) use (&$verificationCode): bool {
            $verificationCode = $mail->verificationCode;

            return $mail->hasTo('student@school.edu.vn');
        });
        $this->withToken($customer['token'])->post('/api/v1/education-discount/verify-code', [
            'code' => $verificationCode,
            'evidence' => UploadedFile::fake()->create('student-card.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->withToken($customer['token'])->getJson('/api/v1/education-discount')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.verified', false)
            ->assertJsonPath('data.discount_percent', null);
        $this->assertDatabaseHas('uu_dai_hoc_tap', [
            'ma_kh' => $customer['id'],
            'email_truong' => 'student@school.edu.vn',
            'doi_tuong' => 'student_university',
            'trang_thai' => 'pending',
        ]);
        $this->insertPublishedProduct('LAPTOP0001', 20000000, 2);
        $this->withToken($customer['token'])->postJson('/api/v1/cart/items', ['product_id' => 'LAPTOP0001'])->assertCreated();
        $this->checkout($customer['token'])->assertJsonPath('data.discount', 0);

        $admin = $this->createAdminAndLogin();
        $this->withToken($admin)->getJson('/api/v1/admin/education-discounts')
            ->assertOk()
            ->assertJsonPath('data.0.customer_id', $customer['id'])
            ->assertJsonPath('data.0.has_evidence', true);
        $this->withToken($customer['token'])->patchJson("/api/v1/admin/education-discounts/{$customer['id']}", [
            'status' => 'approved',
        ])->assertForbidden();
        $this->withToken($admin)->get("/api/v1/admin/education-discounts/{$customer['id']}/evidence")
            ->assertOk();
        $this->withToken($admin)->patchJson("/api/v1/admin/education-discounts/{$customer['id']}", [
            'status' => 'approved',
        ])->assertOk();
        $this->withToken($customer['token'])->getJson('/api/v1/education-discount')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.discount_percent', 9);
    }

    public function test_education_discount_is_capped_and_not_stacked_with_a_voucher(): void
    {
        $customer = $this->registerCustomer();
        DB::table('uu_dai_hoc_tap')->insert([
            'ma_kh' => $customer['id'],
            'email_truong' => 'lecturer@school.edu.vn',
            'doi_tuong' => 'lecturer_university',
            'verified_at' => now(),
            'trang_thai' => 'approved',
            'minh_chung_path' => 'education-discounts/lecturer/proof.pdf',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertPublishedProduct('LAPTOP0001', 20000000, 2);
        $this->withToken($customer['token'])->postJson('/api/v1/cart/items', ['product_id' => 'LAPTOP0001'])->assertCreated();

        $order = $this->withToken($customer['token'])
            ->withHeader('Idempotency-Key', '045a7dc7-7f36-44a7-8eb9-15eae4a1d409')
            ->postJson('/api/v1/orders', [
                'recipient_name' => 'Test Customer',
                'phone' => '0901234567',
                'address' => '1 Test Street',
                'district' => 'Quan 1',
                'city' => 'TP Ho Chi Minh',
                'payment_method' => 'cash',
            ])
            ->assertCreated()
            ->assertJsonPath('data.discount', 2000000)
            ->assertJsonPath('data.total', 18000000);

        $this->assertDatabaseHas('don_hang', [
            'ma_dh' => $order->json('data.id'),
            'tien_giam' => 2000000,
            'loai_uu_dai' => 'education',
        ]);
    }

    public function test_checkout_retries_with_the_same_idempotency_key_return_the_existing_order(): void
    {
        $token = $this->registerCustomer()['token'];
        $this->insertPublishedProduct('LAPTOP0001', 15000000, 2);
        $this->withToken($token)->postJson('/api/v1/cart/items', ['product_id' => 'LAPTOP0001'])->assertCreated();
        $headers = ['Idempotency-Key' => '045a7dc7-7f36-44a7-8eb9-15eae4a1d405'];
        $payload = [
            'recipient_name' => 'Test Customer',
            'phone' => '0901234567',
            'address' => '1 Test Street',
            'district' => 'Quan 1',
            'city' => 'TP Ho Chi Minh',
            'payment_method' => 'cash',
        ];

        $first = $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/orders', $payload)->assertCreated();
        $second = $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/orders', $payload)
            ->assertOk()
            ->assertJsonPath('data.replayed', true);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, DB::table('don_hang')->count());
        $this->assertDatabaseHas('san_pham', ['ma_sp' => 'LAPTOP0001', 'so_luong' => 1]);
    }

    public function test_voucher_is_limited_to_one_redemption_per_customer_and_refunded_on_cancellation(): void
    {
        $token = $this->registerCustomer()['token'];
        $this->insertPublishedProduct('LAPTOP0001', 15000000, 3);
        $admin = $this->createAdminAndLogin();
        $this->withToken($admin)->postJson('/api/v1/admin/vouchers', [
            'code' => 'ONCE-ONLY',
            'discount_type' => 'fixed',
            'discount_value' => 500000,
            'usage_limit' => 2,
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/vouchers/ONCE-ONLY/claim')->assertOk();
        $this->withToken($token)->postJson('/api/v1/cart/items', ['product_id' => 'LAPTOP0001'])->assertCreated();
        $first = $this->checkoutWithVoucher($token, 'ONCE-ONLY', '045a7dc7-7f36-44a7-8eb9-15eae4a1d406');
        $orderId = $first->json('data.id');

        $this->withToken($token)->postJson('/api/v1/cart/items', ['product_id' => 'LAPTOP0001'])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/vouchers/validate', ['code' => 'ONCE-ONLY'])->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/orders/{$orderId}/cancel")->assertOk();

        $this->assertDatabaseHas('phieu_giam_gia', ['code' => 'ONCE-ONLY', 'usage_count' => 0]);
        $this->assertDatabaseMissing('lich_su_su_dung_voucher', ['ma_dh' => $orderId]);
        $this->checkoutWithVoucher($token, 'ONCE-ONLY', '045a7dc7-7f36-44a7-8eb9-15eae4a1d407')
            ->assertCreated();
    }

    public function test_customer_can_merge_a_persisted_guest_cart_without_ignoring_stock_limits(): void
    {
        $token = $this->registerCustomer()['token'];
        $this->insertPublishedProduct('LAPTOP0001', 15000000, 3);

        $this->withToken($token)->postJson('/api/v1/cart/merge', [
            'items' => [['product_id' => 'LAPTOP0001', 'quantity' => 2]],
        ])->assertOk()->assertJsonPath('data.items.0.quantity', 2);
        $this->withToken($token)->postJson('/api/v1/cart/merge', [
            'items' => [['product_id' => 'LAPTOP0001', 'quantity' => 2]],
        ])->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/cart')
            ->assertOk()->assertJsonPath('data.items.0.quantity', 2);
    }

    public function test_only_delivered_customers_can_review_and_can_read_reviews(): void
    {
        $token = $this->registerCustomer()['token'];
        $admin = $this->createAdminAndLogin();
        $this->insertPublishedProduct('LAPTOP0001', 15000000, 1);
        $this->withToken($token)->postJson('/api/v1/cart/items', ['product_id' => 'LAPTOP0001'])->assertCreated();
        $orderId = $this->checkout($token)->json('data.id');

        $this->withToken($token)->postJson('/api/v1/products/LAPTOP0001/reviews', [
            'rating' => 5, 'content' => 'Tốt, đúng mô tả.',
        ])->assertUnprocessable();

        $this->withToken($admin)->patchJson("/api/v1/admin/orders/{$orderId}", [
            'action' => 'status', 'status' => 'Dang Giao',
        ])->assertOk();
        $this->withToken($admin)->patchJson("/api/v1/admin/orders/{$orderId}", [
            'action' => 'status', 'status' => 'Da Giao',
        ])->assertOk();
        $this->withToken($admin)->patchJson("/api/v1/admin/orders/{$orderId}", [
            'action' => 'mark_paid',
        ])->assertOk();
        $this->assertDatabaseHas('thanh_toan', ['ma_dh' => $orderId, 'trang_thai' => 'Da Thanh Toan']);

        $this->withToken($token)->postJson('/api/v1/products/LAPTOP0001/reviews', [
            'rating' => 5, 'content' => 'Tốt, đúng mô tả.',
        ])->assertCreated();
        DB::table('danh_gia_mau')->insert([
            'ma_sp' => 'LAPTOP0001',
            'name' => 'TechStore · đánh giá mẫu',
            'rating' => 5,
            'content' => 'ĐÁNH GIÁ MẪU — Nội dung minh họa, không phải phản hồi khách hàng thật.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->getJson('/api/v1/products/laptop0001/reviews')
            ->assertOk()
            ->assertJsonPath('meta.average_rating', 5)
            ->assertJsonPath('meta.review_count', 1)
            ->assertJsonPath('meta.demo_review_count', 1)
            ->assertJsonFragment(['is_demo' => true])
            ->assertJsonFragment(['is_demo' => false]);
    }

    public function test_admin_can_soft_delete_restore_and_receive_serialized_stock(): void
    {
        $admin = $this->createAdminAndLogin();
        $this->insertPublishedProduct('LAPTOP0001', 15000000, 0);

        $this->withToken($admin)->deleteJson('/api/v1/admin/products/LAPTOP0001')->assertOk();
        $this->getJson('/api/v1/products/laptop0001')->assertNotFound();
        $this->withToken($admin)->getJson('/api/v1/admin/products/trash')
            ->assertOk()->assertJsonPath('data.0.ma_sp', 'LAPTOP0001');
        $this->withToken($admin)->postJson('/api/v1/admin/products/LAPTOP0001/restore')->assertOk();

        $receipt = $this->withToken($admin)->postJson('/api/v1/admin/stock-receipts', [
            'supplier' => 'Nhà cung cấp A',
            'reference' => 'PO-001',
            'items' => [[
                'product_id' => 'LAPTOP0001',
                'unit_cost' => 12000000,
                'serials' => ['SERIAL-001', 'SERIAL-002'],
            ]],
        ])->assertCreated();
        $this->assertDatabaseHas('san_pham', ['ma_sp' => 'LAPTOP0001', 'so_luong' => 2]);

        $token = $this->registerCustomer()['token'];
        $this->withToken($token)->postJson('/api/v1/cart/items', ['product_id' => 'LAPTOP0001'])->assertCreated();
        $orderId = $this->checkout($token)->json('data.id');
        $this->assertDatabaseHas('so_serial_san_pham', [
            'serial_number' => 'SERIAL-001',
            'status' => 'Reserved',
            'ma_dh' => $orderId,
        ]);

        $this->withToken($admin)->patchJson("/api/v1/admin/orders/{$orderId}", [
            'action' => 'status', 'status' => 'Dang Giao',
        ])->assertOk();
        $this->withToken($admin)->patchJson("/api/v1/admin/orders/{$orderId}", [
            'action' => 'status', 'status' => 'Da Giao',
        ])->assertOk();
        $this->assertDatabaseHas('so_serial_san_pham', ['serial_number' => 'SERIAL-001', 'status' => 'Sold']);
        $this->assertDatabaseHas('bao_hanh', ['ma_dh' => $orderId, 'status' => 'active']);
        $this->withToken($token)->getJson('/api/v1/warranties')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($receipt->json('data.id'), DB::table('phieu_nhap_kho')->value('id'));
    }

    public function test_workflows_support_the_existing_vietnamese_table_columns(): void
    {
        Schema::dropIfExists('bao_hanh');
        Schema::dropIfExists('so_serial_san_pham');
        Schema::dropIfExists('chi_tiet_phieu_nhap_kho');
        Schema::dropIfExists('phieu_nhap_kho');
        Schema::dropIfExists('nha_cung_cap');
        Schema::dropIfExists('phieu_giam_gia');
        Schema::dropIfExists('danh_gia');

        Schema::create('danh_gia', function (Blueprint $table): void {
            $table->increments('id_danh_gia');
            $table->char('ma_sp', 10);
            $table->string('ma_kh', 20);
            $table->unsignedTinyInteger('so_sao');
            $table->text('binh_luan')->nullable();
            $table->timestamp('thoi_gian_tao')->useCurrent();
        });
        Schema::create('phieu_giam_gia', function (Blueprint $table): void {
            $table->increments('id_voucher');
            $table->string('ma_code', 50)->unique();
            $table->string('loai_giam', 20);
            $table->decimal('gia_tri_giam', 10, 2);
            $table->decimal('gia_tri_don_toi_thieu', 10, 2)->default(0);
            $table->decimal('giam_toi_da', 10, 2)->nullable();
            $table->integer('so_luong')->nullable();
            $table->integer('da_dung')->default(0);
            $table->dateTime('ngay_bat_dau')->nullable();
            $table->dateTime('ngay_ket_thuc')->nullable();
            $table->boolean('trang_thai')->default(true);
        });
        Schema::create('nha_cung_cap', function (Blueprint $table): void {
            $table->increments('id_nha_cung_cap');
            $table->string('ten_nha_cung_cap', 255);
            $table->string('dien_thoai', 20)->nullable();
            $table->string('email')->nullable();
            $table->text('dia_chi')->nullable();
        });
        Schema::create('phieu_nhap_kho', function (Blueprint $table): void {
            $table->string('id_phieu_nhap', 20)->primary();
            $table->unsignedInteger('id_nha_cung_cap');
            $table->string('tk_admin', 20);
            $table->decimal('tong_chi_phi', 12, 2);
            $table->dateTime('ngay_nhap');
            $table->string('reference', 100)->nullable();
        });
        Schema::create('chi_tiet_phieu_nhap', function (Blueprint $table): void {
            $table->string('id_phieu_nhap', 20);
            $table->char('ma_sp', 10);
            $table->integer('so_luong');
            $table->decimal('gia_nhap', 10, 2);
            $table->primary(['id_phieu_nhap', 'ma_sp']);
        });
        Schema::create('so_serial_san_pham', function (Blueprint $table): void {
            $table->increments('id_serial');
            $table->char('ma_sp', 10);
            $table->string('so_serial', 100)->unique();
            $table->string('trang_thai', 20)->default('In_Stock');
            $table->dateTime('ngay_nhap')->nullable();
            $table->string('id_phieu_nhap', 20)->nullable();
            $table->char('ma_dh', 10)->nullable()->index();
        });
        Schema::create('bao_hanh', function (Blueprint $table): void {
            $table->string('id_bao_hanh', 20)->primary();
            $table->unsignedInteger('id_serial');
            $table->string('ma_kh', 20);
            $table->char('ma_dh', 10);
            $table->date('ngay_bat_dau');
            $table->date('ngay_het_han');
            $table->text('ghi_chu')->nullable();
        });

        $customer = $this->registerCustomer();
        $admin = $this->createAdminAndLogin();
        $this->insertPublishedProduct('LAPTOP0001', 15000000, 0);
        $receipt = $this->withToken($admin)->postJson('/api/v1/admin/stock-receipts', [
            'supplier' => 'Nhà cung cấp A',
            'reference' => 'PO-OLD-001',
            'items' => [[
                'product_id' => 'LAPTOP0001',
                'unit_cost' => 12000000,
                'serials' => ['OLD-SERIAL-001'],
            ]],
        ])->assertCreated();
        $this->assertStringStartsWith('IMP', $receipt->json('data.id'));

        $this->withToken($admin)->postJson('/api/v1/admin/vouchers', [
            'code' => 'OLD-SCHEMA',
            'discount_type' => 'fixed',
            'discount_value' => 1000000,
            'usage_limit' => 1,
        ])->assertCreated();
        $this->withToken($customer['token'])->postJson('/api/v1/vouchers/OLD-SCHEMA/claim')->assertOk();
        $this->withToken($customer['token'])->postJson('/api/v1/cart/items', ['product_id' => 'LAPTOP0001'])->assertCreated();
        $order = $this->withToken($customer['token'])->withHeader('Idempotency-Key', '045a7dc7-7f36-44a7-8eb9-15eae4a1d408')->postJson('/api/v1/orders', [
            'recipient_name' => 'Test Customer',
            'phone' => '0901234567',
            'address' => '1 Test Street',
            'district' => 'Quan 1',
            'city' => 'TP Ho Chi Minh',
            'payment_method' => 'cash',
            'voucher_code' => 'OLD-SCHEMA',
        ])->assertCreated();
        $orderId = $order->json('data.id');
        $this->assertDatabaseHas('phieu_giam_gia', ['ma_code' => 'OLD-SCHEMA', 'da_dung' => 1]);
        $this->assertDatabaseHas('so_serial_san_pham', ['so_serial' => 'OLD-SERIAL-001', 'ma_dh' => $orderId]);

        $this->withToken($admin)->patchJson("/api/v1/admin/orders/{$orderId}", [
            'action' => 'status', 'status' => 'Dang Giao',
        ])->assertOk();
        $this->withToken($admin)->patchJson("/api/v1/admin/orders/{$orderId}", [
            'action' => 'status', 'status' => 'Da Giao',
        ])->assertOk();
        $this->assertDatabaseHas('so_serial_san_pham', ['so_serial' => 'OLD-SERIAL-001', 'trang_thai' => 'Sold']);
        $this->assertDatabaseHas('bao_hanh', ['ma_dh' => $orderId, 'id_serial' => 1]);

        $this->withToken($customer['token'])->postJson('/api/v1/products/LAPTOP0001/reviews', [
            'rating' => 5, 'content' => 'Hoạt động với schema cũ.',
        ])->assertCreated();
        $this->getJson('/api/v1/products/laptop0001/reviews')
            ->assertOk()
            ->assertJsonPath('meta.average_rating', 5)
            ->assertJsonPath('data.0.content', 'Hoạt động với schema cũ.');
    }

    public function test_workflow_migration_repairs_a_partially_created_voucher_history_table(): void
    {
        Schema::dropIfExists('lich_su_su_dung_voucher');
        Schema::create('lich_su_su_dung_voucher', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40);
            $table->string('ma_kh', 20);
            $table->char('ma_dh', 10)->unique();
            $table->decimal('discount_amount', 12, 0);
            $table->timestamp('created_at')->useCurrent();
        });

        $migration = require database_path('migrations/2026_10_23_000001_add_store_workflows.php');
        $migration->up();

        $foreignKeys = collect(Schema::getForeignKeys('lich_su_su_dung_voucher'));
        $this->assertTrue($foreignKeys->contains(
            fn (array $key): bool => $key['columns'] === ['ma_kh']
                && $key['foreign_table'] === 'nguoi_dung',
        ));
        $this->assertTrue($foreignKeys->contains(
            fn (array $key): bool => $key['columns'] === ['ma_dh']
                && $key['foreign_table'] === 'don_hang',
        ));
    }

    private function registerCustomer(): array
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test Customer',
            'username' => 'customer',
            'email' => 'customer@example.test',
            'phone' => '0901234567',
            'password' => 'strong-password',
            'password_confirmation' => 'strong-password',
        ])->assertCreated();

        return ['id' => $response->json('data.id'), 'token' => $response->json('token')];
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

    private function checkout(string $token)
    {
        return $this->withToken($token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/orders', [
                'recipient_name' => 'Test Customer',
                'phone' => '0901234567',
                'address' => '1 Test Street',
                'district' => 'Quan 1',
                'city' => 'TP Ho Chi Minh',
                'payment_method' => 'cash',
            ])->assertCreated();
    }

    private function checkoutWithVoucher(string $token, string $code, string $idempotencyKey)
    {
        return $this->withToken($token)->withHeader('Idempotency-Key', $idempotencyKey)->postJson('/api/v1/orders', [
            'recipient_name' => 'Test Customer',
            'phone' => '0901234567',
            'address' => '1 Test Street',
            'district' => 'Quan 1',
            'city' => 'TP Ho Chi Minh',
            'payment_method' => 'cash',
            'voucher_code' => $code,
        ]);
    }
}
