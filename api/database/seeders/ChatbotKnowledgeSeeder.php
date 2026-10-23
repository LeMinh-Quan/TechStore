<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ChatbotKnowledgeSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('chatbot_huan_luyen')) {
            throw new \RuntimeException('Run php artisan migrate before seeding chatbot knowledge.');
        }

        $entries = [
            'greeting' => [
                'Xin chào',
                'Chào shop',
                'Có ai hỗ trợ không?',
                'Tôi cần tư vấn',
                'Bắt đầu hỗ trợ giúp tôi',
            ],
            'payment' => [
                'Thanh toán bằng cách nào?',
                'Shop có nhận tiền mặt không?',
                'Có thể trả tiền khi nhận hàng không?',
                'Chuyển khoản có hoạt động không?',
                'Tôi có thể thanh toán online không?',
            ],
            'checkout' => [
                'Làm sao để đặt hàng?',
                'Hướng dẫn mua hàng trên website',
                'Cách thêm sản phẩm vào đơn hàng',
                'Tôi muốn tạo đơn mua hàng',
                'Các bước đặt hàng như thế nào?',
            ],
            'order' => [
                'Kiểm tra đơn hàng ở đâu?',
                'Xem trạng thái đơn hàng thế nào?',
                'Tôi muốn xem lịch sử mua hàng',
                'Có thể hủy đơn hàng không?',
                'Đơn hàng của tôi đang ở trạng thái nào?',
            ],
            'delivery' => [
                'Shop giao hàng như thế nào?',
                'Phí vận chuyển là bao nhiêu?',
                'Khi nào tôi nhận được hàng?',
                'Theo dõi giao hàng ở đâu?',
                'Tôi muốn hỏi về đơn giao hàng',
            ],
            'warranty' => [
                'Sản phẩm được bảo hành bao lâu?',
                'Chính sách đổi trả như thế nào?',
                'Sản phẩm bị lỗi thì xử lý ra sao?',
                'Tôi cần kiểm tra bảo hành',
                'Làm thế nào để yêu cầu đổi trả?',
            ],
        ];

        $answers = [
            'greeting' => 'Xin chào! Mình có thể tư vấn cấu hình, tìm sản phẩm đang bán hoặc hỗ trợ đơn hàng.',
            'payment' => 'Chủ đề: thanh toán. Bạn có thể chọn thanh toán khi nhận hàng hoặc chuyển khoản mô phỏng lúc đặt hàng. Chuyển khoản trong phiên bản này chưa kết nối ngân hàng.',
            'checkout' => 'Chủ đề: đặt hàng. Bạn chọn sản phẩm, thêm vào giỏ, đăng nhập rồi điền thông tin giao hàng để đặt hàng. Hiện cửa hàng hỗ trợ thanh toán khi nhận hàng hoặc chuyển khoản mô phỏng.',
            'order' => 'Chủ đề: đơn hàng. Đăng nhập tài khoản, mở mục đơn hàng để xem trạng thái hoặc hủy đơn khi đơn vẫn đang chờ xác nhận.',
            'delivery' => 'Chủ đề: giao hàng. Bạn có thể nhập địa chỉ giao hàng khi đặt đơn. Để kiểm tra phí hoặc tiến độ giao hàng cụ thể, hãy gửi mã đơn hoặc chọn “Gặp nhân viên”.',
            'warranty' => 'Chủ đề: bảo hành / đổi trả. Thời hạn bảo hành tùy theo sản phẩm. Bạn hãy mở trang chi tiết sản phẩm để xem thông tin bảo hành hoặc chọn “Gặp nhân viên” để được kiểm tra cụ thể.',
        ];

        $now = now();
        $inserts = [];
        foreach ($entries as $intent => $questions) {
            foreach ($questions as $question) {
                $exists = DB::table('chatbot_huan_luyen')
                    ->where('y_dinh', $intent)
                    ->where('cau_hoi', $question)
                    ->exists();
                if ($exists) {
                    continue;
                }

                $inserts[] = [
                    'y_dinh' => $intent,
                    'cau_hoi' => $question,
                    'phan_hoi' => $answers[$intent],
                    'trang_thai' => 1,
                    'thoi_gian_tao' => $now,
                ];
            }
        }

        foreach (array_chunk($inserts, 100) as $batch) {
            DB::table('chatbot_huan_luyen')->insert($batch);
        }

        $this->command?->info('Chatbot knowledge: '.count($inserts).' new question examples added; existing records preserved.');
    }
}
