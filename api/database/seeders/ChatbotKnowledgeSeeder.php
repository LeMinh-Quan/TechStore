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
                'Hello',
                'Hi shop',
                'Chào bạn',
                'Alo',
                'Shop ơi',
                'Chào buổi sáng',
                'Chào buổi tối',
                'Có ai ở đó không?',
                'Cho mình hỏi chút',
                'Mình muốn hỏi một chút',
                'Bạn ơi',
                'Xin chào, mình cần hỗ trợ',
                'Chào admin',
                'Hey',
                'Shop còn online không?',
            ],
            'payment' => [
                'Thanh toán bằng cách nào?',
                'Shop có nhận tiền mặt không?',
                'Có thể trả tiền khi nhận hàng không?',
                'Chuyển khoản có hoạt động không?',
                'Tôi có thể thanh toán online không?',
                'Có hỗ trợ COD không?',
                'Thanh toán qua ví điện tử được không?',
                'Shop nhận thẻ ATM không?',
                'Có quẹt thẻ tín dụng được không?',
                'Số tài khoản ngân hàng của shop là gì?',
                'Mình muốn chuyển khoản trước thì làm sao?',
                'Có thanh toán bằng QR không?',
                'Hình thức thanh toán nào được hỗ trợ?',
                'Trả tiền mặt khi shipper giao tới được không?',
                'Chuyển khoản xong có cần gửi biên lai không?',
                'Thanh toán Momo được không?',
                'Mình có phải đặt cọc không?',
                'Có thể thanh toán một phần trước không?',
                'Thanh toán thất bại thì làm sao?',
                'Phương thức thanh toán nào an toàn nhất?',
            ],
            'checkout' => [
                'Làm sao để đặt hàng?',
                'Hướng dẫn mua hàng trên website',
                'Cách thêm sản phẩm vào đơn hàng',
                'Tôi muốn tạo đơn mua hàng',
                'Các bước đặt hàng như thế nào?',
                'Mình muốn mua sản phẩm này',
                'Đặt hàng online thế nào?',
                'Thêm vào giỏ hàng ở đâu?',
                'Giỏ hàng của tôi ở đâu?',
                'Có cần đăng nhập mới đặt hàng được không?',
                'Đặt hàng xong có nhận được xác nhận không?',
                'Mình muốn mua nhiều sản phẩm cùng lúc',
                'Đổi địa chỉ nhận hàng lúc đặt thế nào?',
                'Có thể đặt hàng không cần tài khoản không?',
                'Đặt hàng bị lỗi thì làm sao?',
                'Sửa số lượng trong giỏ hàng thế nào?',
                'Xóa sản phẩm khỏi giỏ hàng như nào?',
                'Mua hàng cho người khác được không?',
                'Nút đặt hàng bấm không được',
            ],
            'order' => [
                'Kiểm tra đơn hàng ở đâu?',
                'Xem trạng thái đơn hàng thế nào?',
                'Tôi muốn xem lịch sử mua hàng',
                'Có thể hủy đơn hàng không?',
                'Đơn hàng của tôi đang ở trạng thái nào?',
                'Đơn hàng của mình đã được xác nhận chưa?',
                'Mình muốn hủy đơn vừa đặt',
                'Đơn đang chờ xác nhận bao lâu?',
                'Tra cứu đơn bằng mã đơn',
                'Sao đơn của mình chưa được duyệt?',
                'Mình đặt nhầm sản phẩm, đổi được không?',
                'Có sửa được đơn sau khi đặt không?',
                'Lịch sử đơn hàng nằm ở mục nào?',
                'Đơn hàng bị hủy là sao?',
                'Mình muốn đổi số điện thoại trong đơn',
                'Đặt xong rồi mà không thấy đơn đâu',
                'Làm sao biết đơn đã được giao chưa?',
                'Mình quên mã đơn hàng thì tra thế nào?',
                'Đơn hàng bị trùng, hủy giúp mình',
            ],
            'delivery' => [
                'Shop giao hàng như thế nào?',
                'Phí vận chuyển là bao nhiêu?',
                'Khi nào tôi nhận được hàng?',
                'Theo dõi giao hàng ở đâu?',
                'Tôi muốn hỏi về đơn giao hàng',
                'Giao hàng mất bao lâu?',
                'Shop có giao hàng tận nơi không?',
                'Có miễn phí vận chuyển không?',
                'Giao hàng ở tỉnh có được không?',
                'Ship về Hà Nội mất mấy ngày?',
                'Phí ship đơn trên 5 triệu là bao nhiêu?',
                'Shop giao qua đơn vị vận chuyển nào?',
                'Có giao hàng nhanh trong ngày không?',
                'Mình muốn đổi địa chỉ giao hàng',
                'Shipper không gọi cho mình thì sao?',
                'Có cho kiểm tra hàng trước khi thanh toán không?',
                'Giao hàng vào cuối tuần được không?',
                'Hàng đã giao nhưng mình chưa nhận được',
                'Có thể hẹn giờ giao hàng không?',
            ],
            'warranty' => [
                'Sản phẩm được bảo hành bao lâu?',
                'Chính sách đổi trả như thế nào?',
                'Sản phẩm bị lỗi thì xử lý ra sao?',
                'Tôi cần kiểm tra bảo hành',
                'Làm thế nào để yêu cầu đổi trả?',
                'Bảo hành chính hãng bao nhiêu tháng?',
                'Laptop bảo hành mấy năm?',
                'Mình đổi trả trong bao nhiêu ngày?',
                'Sản phẩm lỗi có được đổi mới không?',
                'Bảo hành có cần giữ hóa đơn không?',
                'Mang sản phẩm đi bảo hành ở đâu?',
                'Hàng đã qua sử dụng có bảo hành không?',
                'Máy bị vào nước có được bảo hành không?',
                'Tra cứu bảo hành bằng số serial',
                'Thời gian xử lý bảo hành là bao lâu?',
                'Mình muốn trả hàng hoàn tiền',
                'Sản phẩm giao tới bị hỏng thì làm gì?',
                'Bảo hành tai nghe bao lâu?',
                'Điều kiện đổi trả là gì?',
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
