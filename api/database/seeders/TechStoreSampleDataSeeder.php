<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class TechStoreSampleDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Demo accounts with preset passwords must not be seeded in production.');
        }

        $requiredTables = [
            'cua_hang',
            'quan_tri',
            'san_pham',
            'nguoi_dung',
            'dia_chi',
            'gio_hang',
            'chi_tiet_gio_hang',
            'don_hang',
            'chi_tiet_don_hang',
            'thanh_toan',
        ];

        foreach ($requiredTables as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Run the database migrations before seeding; missing table: {$table}.");
            }
        }

        $demoPassword = Str::random(32);

        DB::transaction(function () use ($demoPassword): void {
            $stores = [
                $this->seedRecord('cua_hang', ['ten_cua_hang' => 'TechStore Quận 1'], [
                    'dia_chi' => 'Địa chỉ mẫu, Quận 1, TP. Hồ Chí Minh',
                    'dien_thoai' => '0000000001',
                    'trang_thai' => true,
                ]),
                $this->seedRecord('cua_hang', ['ten_cua_hang' => 'TechStore Thủ Đức'], [
                    'dia_chi' => 'Địa chỉ mẫu, TP. Thủ Đức, TP. Hồ Chí Minh',
                    'dien_thoai' => '0000000002',
                    'trang_thai' => true,
                ]),
            ];
            $storeIds = [(int) $stores[0]->id_cua_hang, (int) $stores[1]->id_cua_hang];

            $admins = [
                ['id' => 'demo-owner', 'name' => 'Demo Owner', 'email' => 'demo-owner@example.test', 'role' => 'chu_quan', 'store' => null],
                ['id' => 'demo-business', 'name' => 'Demo Business Owner', 'email' => 'demo-business@example.test', 'role' => 'chu_doanh_nghiep', 'store' => null],
                ['id' => 'demo-manager-1', 'name' => 'Demo Branch Manager 1', 'email' => 'demo-manager-1@example.test', 'role' => 'giam_doc_chi_nhanh', 'store' => $storeIds[0]],
                ['id' => 'demo-staff-1', 'name' => 'Demo Staff 1', 'email' => 'demo-staff-1@example.test', 'role' => 'nhan_vien', 'store' => $storeIds[0]],
                ['id' => 'demo-manager-2', 'name' => 'Demo Branch Manager 2', 'email' => 'demo-manager-2@example.test', 'role' => 'giam_doc_chi_nhanh', 'store' => $storeIds[1]],
                ['id' => 'demo-staff-2', 'name' => 'Demo Staff 2', 'email' => 'demo-staff-2@example.test', 'role' => 'nhan_vien', 'store' => $storeIds[1]],
                ['id' => 'demo-manager-3', 'name' => 'Demo Branch Manager 3', 'email' => 'demo-manager-3@example.test', 'role' => 'giam_doc_chi_nhanh', 'store' => $storeIds[0]],
            ];

            foreach ($admins as $admin) {
                $this->seedRecord('quan_tri', ['tk_admin' => $admin['id']], [
                    'mat_khau' => Hash::make($demoPassword),
                    'ten_admin' => $admin['name'],
                    'email' => $admin['email'],
                    'role' => $admin['role'],
                    'id_cua_hang' => $admin['store'],
                ]);
            }

            $products = [
                ['id' => 'DEMO000001', 'name' => 'Laptop TechStore Core i5 16GB 512GB', 'category' => 'Laptop', 'brand' => 'TechStore', 'price' => 18990000, 'stock' => 11, 'store' => $storeIds[0]],
                ['id' => 'DEMO000002', 'name' => 'Điện thoại TechStore Nova 256GB', 'category' => 'Điện thoại', 'brand' => 'TechStore', 'price' => 12990000, 'stock' => 18, 'store' => $storeIds[1]],
                ['id' => 'DEMO000003', 'name' => 'Tai nghe không dây TechStore Air', 'category' => 'Phụ kiện', 'brand' => 'TechStore', 'price' => 1490000, 'stock' => 30, 'store' => $storeIds[0]],
            ];

            foreach ($products as $index => $product) {
                $this->seedRecord('san_pham', ['ma_sp' => $product['id']], [
                    'ten_sp' => $product['name'],
                    'loai' => $product['category'],
                    'thuong_hieu' => $product['brand'],
                    'mo_ta' => 'Sản phẩm mẫu dùng để kiểm thử quy trình mua hàng của TechStore.',
                    'gia_ban' => $product['price'],
                    'gia_niem_yet' => $product['price'],
                    'so_luong' => $product['stock'],
                    'stt' => 990001 + $index,
                    'slug' => strtolower($product['id']).'-'.($index + 1),
                    'trang_thai_hien_thi' => 'published',
                    'tk_admin_tao' => 'demo-owner',
                    'id_cua_hang' => $product['store'],
                    'la_hang_cu' => false,
                ]);

                $this->seedOptionalRecord('ton_kho_cua_hang', [
                    'id_cua_hang' => $product['store'],
                    'ma_sp' => $product['id'],
                ], ['so_luong_ton' => $product['stock']]);

                $this->seedOptionalRecord('san_pham_hinh_anh', [
                    'ma_sp' => $product['id'],
                    'duong_dan_anh' => '/images/demo/product-placeholder.svg',
                ], [
                    'alt_text' => $product['name'],
                    'thu_tu' => 1,
                ]);

                $this->seedOptionalRecord('nhat_ky_ton_kho', [
                    'ma_sp' => $product['id'],
                    'id_cua_hang' => $product['store'],
                    'tk_admin' => 'demo-owner',
                    'ton_cu' => 0,
                ], [
                    'ton_moi' => $product['stock'],
                    'dia_chi_ip' => '127.0.0.1',
                ]);
            }

            $this->seedOptionalRecord('cpu', ['ma_cpu' => 'CPUDEMO001'], [
                'ten_cpu' => 'Intel Core i5 Demo',
                'mo_ta' => 'Bộ xử lý mẫu cho laptop văn phòng.',
            ]);
            $this->seedOptionalRecord('ram', ['ma_ram' => 'RAMDEMO001'], [
                'dung_luong' => '16GB',
                'loai_ram' => 'DDR5',
                'toc_do' => '5200MHz',
            ]);
            $this->seedOptionalRecord('rom', ['ma_rom' => 'ROMDEMO001'], [
                'dung_luong' => '512GB',
                'loai_rom' => 'SSD NVMe',
                'mo_ta' => 'Ổ cứng mẫu.',
            ]);
            $this->seedOptionalRecord('gpu', ['ma_gpu' => 'GPUDEMO001'], [
                'ten_gpu' => 'Intel Iris Xe Graphics',
                'loai_gpu' => 'Tích hợp',
                'mo_ta' => 'Đồ họa tích hợp mẫu.',
            ]);
            $this->seedOptionalRecord('man_hinh', ['ma_mh' => 'MHDEMO001'], [
                'kich_thuoc' => '15.6 inch',
                'do_phan_giai' => '1920x1080',
                'tan_so' => '60Hz',
                'cong_nghe' => 'IPS',
            ]);
            $this->seedOptionalRecord('he_dieu_hanh', ['ma_hdh' => 'HDHDEMO001'], [
                'ten_hdh' => 'Windows',
                'phien_ban' => '11 Home',
            ]);
            $this->seedOptionalRecord('mau_sac', ['ma_mau' => 'MAUDEMO001'], [
                'ten_mau' => 'Bạc',
                'ma_hex' => '#C0C0C0',
            ]);
            $this->seedOptionalRecord('chi_tiet_san_pham', ['ma_sp' => $products[0]['id']], [
                'cpu' => 'CPUDEMO001',
                'ram' => 'RAMDEMO001',
                'rom' => 'ROMDEMO001',
                'gpu' => 'GPUDEMO001',
                'man_hinh' => 'MHDEMO001',
                'he_dieu_hanh' => 'HDHDEMO001',
                'mau_sac' => 'MAUDEMO001',
                'chi_tiet' => 'Laptop mẫu: Core i5, RAM 16GB, SSD 512GB, màn hình IPS 15.6 inch.',
            ]);

            $customers = [
                ['id' => 'KHDEMO000001', 'name' => 'Demo Customer 1', 'username' => 'customer.demo.1', 'email' => 'customer1@example.test', 'phone' => '0000000003'],
                ['id' => 'KHDEMO000002', 'name' => 'Demo Customer 2', 'username' => 'customer.demo.2', 'email' => 'customer2@example.test', 'phone' => '0000000004'],
            ];

            foreach ($customers as $index => $customer) {
                $this->seedRecord('nguoi_dung', ['ma_kh' => $customer['id']], [
                    'ten_dang_nhap' => $customer['username'],
                    'email' => $customer['email'],
                    'mat_khau' => Hash::make($demoPassword),
                    'ten_khach_hang' => $customer['name'],
                    'so_dien_thoai' => $customer['phone'],
                ]);

                $this->seedRecord('dia_chi', [
                    'ma_kh' => $customer['id'],
                    'loai_dia_chi' => 'Nha',
                ], [
                    'dia_chi_giao_hang' => 'Nha',
                    'chi_tiet_dia_chi' => 'Địa chỉ mẫu '.($index + 1),
                    'thanh_pho' => 'TP. Hồ Chí Minh',
                    'quan_huyen' => $index === 0 ? 'Quận 1' : 'TP. Thủ Đức',
                    'phuong_xa' => 'Phường mẫu',
                    'ten_nguoi_nhan' => $customer['name'],
                    'so_dien_thoai' => $customer['phone'],
                ]);
            }

            $this->seedOptionalRecord('san_pham_yeu_thich', [
                'ma_kh' => 'KHDEMO000001',
                'ma_sp' => $products[2]['id'],
            ], ['thoi_gian_tao' => now()->toDateTimeString()]);

            $cartId = 'CART-DEMO-000001';
            $this->seedRecord('gio_hang', ['ma_cart' => $cartId], [
                'ma_kh' => $customers[0]['id'],
                'ngay_tao' => now()->toDateString(),
            ]);
            $this->seedRecord('chi_tiet_gio_hang', [
                'ma_cart' => $cartId,
                'ma_sp' => $products[0]['id'],
            ], [
                'so_luong' => 1,
                'gia' => $products[0]['price'],
            ]);

            $orders = [
                ['id' => 'DHDEMO0001', 'customer' => 0, 'product' => 0, 'quantity' => 1, 'status' => 'Da Giao', 'store' => $storeIds[0], 'staff' => 'demo-staff-1'],
                ['id' => 'DHDEMO0002', 'customer' => 1, 'product' => 1, 'quantity' => 1, 'status' => 'Dang Giao', 'store' => $storeIds[1], 'staff' => 'demo-staff-2', 'voucher' => 'WELCOME10'],
            ];

            foreach ($orders as $index => $order) {
                $customer = $customers[$order['customer']];
                $product = $products[$order['product']];
                $discount = ($order['voucher'] ?? null) === 'WELCOME10' ? 500000 : 0;
                $total = ($product['price'] * $order['quantity']) - $discount;

                $this->seedRecord('don_hang', ['ma_dh' => $order['id']], [
                    'ma_kh' => $customer['id'],
                    'ngay_dat' => now()->subDays($index + 1)->toDateString(),
                    'tong_tien' => $total,
                    'trang_thai' => $order['status'],
                    'ten_nguoi_nhan' => $customer['name'],
                    'dien_thoai_nguoi_nhan' => $customer['phone'],
                    'dia_chi_giao_hang' => 'Địa chỉ mẫu '.($index + 1).($index === 0 ? ', Quận 1' : ', TP. Thủ Đức'),
                    'idempotency_key' => 'demo-order-'.$order['id'],
                    'ma_voucher' => $order['voucher'] ?? null,
                    'tien_giam' => $discount,
                    'id_cua_hang' => $order['store'],
                    'nhan_vien_id' => $order['staff'],
                ]);

                $this->seedRecord('chi_tiet_don_hang', [
                    'ma_dh' => $order['id'],
                    'ma_sp' => $product['id'],
                ], [
                    'so_luong' => $order['quantity'],
                    'don_gia' => $product['price'],
                    'ten_sp_snapshot' => $product['name'],
                    'thong_so_snapshot' => 'Sản phẩm mẫu TechStore',
                ]);

                $this->seedRecord('thanh_toan', ['ma_tt' => 'TTDEMO000'.($index + 1)], [
                    'ma_dh' => $order['id'],
                    'phuong_thuc' => $index === 0 ? 'Tien mat' : 'Chuyen khoan',
                    'ngay_va_gio' => now()->subDays($index + 1)->toDateTimeString(),
                    'trang_thai' => 'Da Thanh Toan',
                ]);

                $this->seedOptionalRecord('nhat_ky_trang_thai_don', [
                    'ma_dh' => $order['id'],
                    'trang_thai' => $order['status'],
                ], [
                    'trang_thai_cu' => 'Cho xac nhan',
                    'ghi_chu' => 'Cập nhật trạng thái đơn hàng mẫu.',
                    'nguoi_thuc_hien' => $order['staff'],
                    'thoi_gian_tao' => now()->subDays($index + 1)->toDateTimeString(),
                ]);

                $this->seedOptionalRecord('thong_bao_nguoi_dung', [
                    'ma_kh' => $customer['id'],
                    'ma_dh' => $order['id'],
                    'loai' => 'order_status',
                ], [
                    'tieu_de' => 'Cập nhật đơn hàng mẫu',
                    'noi_dung' => "Đơn hàng {$order['id']} hiện ở trạng thái {$order['status']}.",
                ]);
            }

            $this->seedSampleVoucher();
            $this->seedOptionalRecord('lich_su_su_dung_voucher', [
                'ma_dh' => 'DHDEMO0002',
            ], [
                'code' => 'WELCOME10',
                'ma_kh' => 'KHDEMO000002',
                'discount_amount' => 500000,
            ]);
            $this->seedOptionalRecord('voucher_da_thu_thap', [
                'ma_kh' => 'KHDEMO000002',
                'code' => 'WELCOME10',
            ], []);
            $this->seedOptionalRecord('yeu_cau_doi_tra', [
                'ma_dh' => 'DHDEMO0001',
            ], [
                'ma_kh' => 'KHDEMO000001',
                'loai_yeu_cau' => 'return',
                'ly_do' => 'changed_mind',
                'mo_ta' => 'Yêu cầu mẫu để kiểm thử quy trình đổi trả.',
                'trang_thai' => 'Cho xu ly',
                'ghi_chu_nhan_vien' => null,
                'nguoi_xu_ly' => null,
            ]);

            foreach ($admins as $admin) {
                $this->seedOptionalRecord('bang_luong', [
                    'tk_admin' => $admin['id'],
                    'nam' => (int) now()->format('Y'),
                    'thang' => (int) now()->format('n'),
                ], [
                    'luong_co_ban' => $admin['role'] === 'chu_quan' ? 0 : 12000000,
                    'phu_cap' => 1000000,
                    'thuong' => 500000,
                    'khau_tru' => 0,
                    'trang_thai' => 'chua_thanh_toan',
                    'ngay_thanh_toan' => null,
                ]);
            }

            $supplier = $this->seedOptionalRecord('nha_cung_cap', ['ten_nha_cung_cap' => 'Nhà phân phối Công nghệ Việt'], [
                'nguoi_lien_he' => 'Bộ phận kinh doanh',
                'dien_thoai' => '0000000005',
                'phone' => '0000000005',
                'email' => 'sales@supplier.example.test',
                'dia_chi' => 'Quận 10, TP. Hồ Chí Minh',
                'contact_name' => 'Bộ phận kinh doanh',
            ], ['name' => 'Nhà phân phối Công nghệ Việt']);

            if ($supplier && Schema::hasTable('phieu_nhap_kho')) {
                $legacyReceipt = Schema::hasColumn('phieu_nhap_kho', 'id_phieu_nhap');
                $receipt = $this->seedOptionalRecord('phieu_nhap_kho', $legacyReceipt
                    ? ['id_phieu_nhap' => 'PNDEMO0001']
                    : ['reference' => 'DEMO-RECEIPT-0001'], [
                        'id_nha_cung_cap' => $supplier->id_nha_cung_cap ?? null,
                        'supplier_id' => $supplier->id ?? null,
                        'tk_admin' => 'demo-owner',
                        'tong_chi_phi' => $products[0]['price'] * 12,
                        'received_at' => now()->subDays(10)->toDateTimeString(),
                        'ngay_nhap' => now()->subDays(10)->toDateTimeString(),
                        'reference' => 'DEMO-RECEIPT-0001',
                        'id_cua_hang' => $storeIds[0],
                    ]);

                if ($receipt) {
                    $legacyReceiptDetails = Schema::hasTable('chi_tiet_phieu_nhap');
                    $receiptId = $legacyReceipt
                        ? 'PNDEMO0001'
                        : (string) $receipt->id;

                    if ($legacyReceiptDetails) {
                        $this->seedOptionalRecord('chi_tiet_phieu_nhap', [
                            'id_phieu_nhap' => $receiptId,
                            'ma_sp' => $products[0]['id'],
                        ], [
                            'so_luong' => 12,
                            'gia_nhap' => 15000000,
                        ]);
                    } elseif (Schema::hasTable('chi_tiet_phieu_nhap_kho')) {
                        $this->seedOptionalRecord('chi_tiet_phieu_nhap_kho', [
                            'stock_receipt_id' => $receipt->id,
                            'ma_sp' => $products[0]['id'],
                        ], ['quantity' => 12]);
                    }

                    $this->seedSampleSerialAndWarranty(
                        $receiptId,
                        $products[0]['id'],
                        $storeIds[0],
                        $customers[0]['id'],
                    );
                }
            }

            $this->seedOptionalRecord('nhat_ky_hoat_dong', [
                'tk_admin' => 'demo-owner',
                'hanh_dong' => 'seed.demo-data',
                'chi_tiet' => 'Khởi tạo dữ liệu mẫu TechStore.',
            ], ['dia_chi_ip' => '127.0.0.1']);

            $this->seedOptionalRecord('bai_viet', ['duong_dan_slug' => 'huong-dan-chon-laptop-mau'], [
                'tieu_de' => 'Hướng dẫn chọn laptop phù hợp nhu cầu',
                'noi_dung' => 'Bài viết mẫu: xác định nhu cầu, ngân sách, cấu hình và chế độ bảo hành trước khi chọn mua laptop.',
                'anh_dai_dien' => null,
                'tk_admin' => 'demo-owner',
            ]);

            $this->seedOptionalRecord('khuyen_mai', ['id_khuyen_mai' => 990001], [
                'tieu_de' => 'Ưu đãi thiết bị công nghệ',
                'mo_ta' => 'Chương trình khuyến mãi mẫu phục vụ kiểm thử.',
                'ngay_bat_dau' => now()->subDays(1)->toDateTimeString(),
                'ngay_ket_thuc' => now()->addDays(30)->toDateTimeString(),
                'trang_thai' => true,
            ]);
            $this->seedOptionalRecord('san_pham_khuyen_mai', [
                'id_khuyen_mai' => 990001,
                'ma_sp' => $products[1]['id'],
            ], []);

            $this->seedOptionalRecord('banner', ['duong_dan_anh' => '/images/demo/techstore-banner.svg'], [
                'tieu_de' => 'Ưu đãi công nghệ tại TechStore',
                'lien_ket' => '/products',
                'vi_tri' => 'home_slider',
                'thu_tu' => 1,
                'trang_thai' => true,
            ]);

            foreach ($products as $product) {
                $this->seedOptionalReview($product['id'], $product['name']);
            }

            $this->command?->info("TechStore sample data seeded. Temporary demo password: {$demoPassword}");
        });
    }

    private function seedSampleVoucher(): void
    {
        if (! Schema::hasTable('phieu_giam_gia')) {
            return;
        }

        if (Schema::hasColumn('phieu_giam_gia', 'ma_code')) {
            $this->seedOptionalRecord('phieu_giam_gia', ['ma_code' => 'WELCOME10'], [
                'loai_giam' => 'percentage',
                'gia_tri_giam' => 10,
                'gia_tri_don_toi_thieu' => 1000000,
                'giam_toi_da' => 500000,
                'so_luong' => 100,
                'da_dung' => 1,
                'ngay_bat_dau' => now()->subDays(1)->toDateTimeString(),
                'ngay_ket_thuc' => now()->addDays(30)->toDateTimeString(),
                'trang_thai' => true,
            ]);

            return;
        }

        $this->seedOptionalRecord('phieu_giam_gia', ['code' => 'WELCOME10'], [
            'discount_type' => 'percent',
            'discount_value' => 10,
            'minimum_order_value' => 1000000,
            'maximum_discount' => 500000,
            'usage_limit' => 100,
            'usage_count' => 1,
            'starts_at' => now()->subDays(1)->toDateTimeString(),
            'expires_at' => now()->addDays(30)->toDateTimeString(),
            'is_active' => true,
        ]);
    }

    private function seedSampleSerialAndWarranty(
        string $receiptId,
        string $productId,
        int $storeId,
        string $customerId,
    ): void {
        if (! Schema::hasTable('so_serial_san_pham')) {
            return;
        }

        $legacySerial = Schema::hasColumn('so_serial_san_pham', 'so_serial');
        $serial = $this->seedOptionalRecord('so_serial_san_pham', $legacySerial
            ? ['so_serial' => 'DEMO-SERIAL-0001']
            : ['serial_number' => 'DEMO-SERIAL-0001'], [
                'ma_sp' => $productId,
                'trang_thai' => 'Sold',
                'status' => 'Sold',
                'ngay_nhap' => now()->subDays(10)->toDateTimeString(),
                'ma_dh' => 'DHDEMO0001',
                'id_phieu_nhap' => $receiptId,
                'stock_receipt_id' => ctype_digit($receiptId) ? (int) $receiptId : null,
                'id_cua_hang' => $storeId,
            ]);

        if (! $serial || ! Schema::hasTable('bao_hanh')) {
            return;
        }

        if (Schema::hasColumn('bao_hanh', 'product_serial_id')) {
            $this->seedOptionalRecord('bao_hanh', [
                'product_serial_id' => $serial->id,
            ], [
                'ma_kh' => $customerId,
                'ma_dh' => 'DHDEMO0001',
                'starts_at' => now()->subDays(1)->toDateString(),
                'expires_at' => now()->addYear()->toDateString(),
                'status' => 'active',
                'trang_thai_xu_ly' => 'active',
                'ghi_chu_xu_ly' => null,
            ]);

            return;
        }

        $this->seedOptionalRecord('bao_hanh', ['id_bao_hanh' => 'BHDEMO0001'], [
            'id_serial' => $serial->id_serial,
            'ma_kh' => $customerId,
            'ma_dh' => 'DHDEMO0001',
            'ngay_bat_dau' => now()->subDays(1)->toDateString(),
            'ngay_het_han' => now()->addYear()->toDateString(),
            'ghi_chu' => 'Bảo hành mẫu trong một năm.',
            'trang_thai_xu_ly' => 'active',
            'ghi_chu_xu_ly' => null,
        ]);
    }

    private function seedOptionalReview(string $productId, string $productName): void
    {
        if (Schema::hasTable('danh_gia')) {
            $values = Schema::hasColumn('danh_gia', 'rating')
                ? [
                    'rating' => 5,
                    'content' => "ĐÁNH GIÁ MẪU — Nội dung minh họa, không phải phản hồi khách hàng thật về {$productName}.",
                ]
                : [
                    'so_sao' => 5,
                    'binh_luan' => "ĐÁNH GIÁ MẪU — Nội dung minh họa, không phải phản hồi khách hàng thật về {$productName}.",
                ];

            $this->seedOptionalRecord('danh_gia', [
                'ma_kh' => 'KHDEMO000001',
                'ma_sp' => $productId,
            ], $values);
        }

        $this->seedOptionalRecord('danh_gia_mau', [
            'ma_sp' => $productId,
            'name' => 'TechStore · đánh giá mẫu',
        ], [
            'rating' => 5,
            'content' => "ĐÁNH GIÁ MẪU — Nội dung minh họa giao diện, không phải phản hồi khách hàng thật về {$productName}.",
        ]);
    }

    private function seedOptionalRecord(string $table, array $identity, array $values, array $alternateIdentity = []): ?object
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        if (! $this->hasColumns($table, $identity)) {
            if ($alternateIdentity === [] || ! $this->hasColumns($table, $alternateIdentity)) {
                return null;
            }

            $identity = $alternateIdentity;
        }

        return $this->seedRecord($table, $identity, $values);
    }

    private function seedRecord(string $table, array $identity, array $values): object
    {
        if (! $this->hasColumns($table, $identity)) {
            throw new RuntimeException("Cannot seed {$table}: one or more identity columns are missing.");
        }

        $recordQuery = DB::table($table);
        foreach ($identity as $column => $value) {
            $recordQuery->where($column, $value);
        }

        $record = $recordQuery->first();
        $attributes = array_filter(
            $values,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY,
        );
        $now = now()->toDateTimeString();
        if (Schema::hasColumn($table, 'created_at')) {
            $attributes['created_at'] ??= $now;
        }
        if (Schema::hasColumn($table, 'updated_at')) {
            $attributes['updated_at'] = $now;
        }

        if ($record) {
            if ($attributes !== []) {
                $recordQuery->update($attributes);
            }
        } else {
            DB::table($table)->insert(array_merge($identity, $attributes));
        }

        $result = DB::table($table);
        foreach ($identity as $column => $value) {
            $result->where($column, $value);
        }

        $seededRecord = $result->first();
        if (! $seededRecord) {
            throw new RuntimeException("Unable to retrieve seeded record from {$table}.");
        }

        return $seededRecord;
    }

    private function hasColumns(string $table, array $values): bool
    {
        foreach (array_keys($values) as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }
}
