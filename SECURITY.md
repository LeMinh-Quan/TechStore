# Bảo mật TechStore

Tài liệu này ghi nhận các biện pháp bảo vệ trong mã nguồn và các yêu cầu còn phụ thuộc môi trường triển khai. Đây không phải chứng nhận an toàn production.

## Đã triển khai

- Laravel API xác thực bearer token bằng hash SHA-256; quyền customer/admin/staff và quyền sở hữu đơn hàng được kiểm tra ở middleware/service. API có giới hạn mặc định 300 request/phút, catalog công khai 120 request/phút, đăng nhập có giới hạn chặt hơn và chatbot nội bộ 60 request/phút theo client IP.
- API kiểm tra `Origin` (hoặc `Referer` khi thiếu `Origin`) cho các request thay đổi dữ liệu, từ chối nguồn không nằm trong `CORS_ALLOWED_ORIGINS` hoặc `APP_URL`. Request không có cả hai header vẫn được hỗ trợ cho client không phải trình duyệt; xác thực vẫn yêu cầu Bearer token và không dựa vào cookie.
- Chatbot giới hạn nội dung JSON 10 KB, câu hỏi 2–500 ký tự, và rate-limit 20 tin nhắn/phút cùng 60 lần tải lịch sử/phút theo client IP. Lịch sử và tin nhắn tiếp theo cần capability token HMAC riêng, hết hạn sau 30 ngày; UUID có thể chọn không còn đủ để đọc hoặc chiếm phiên.
- Chatbot và API trả security headers chống MIME sniffing/clickjacking, giới hạn browser capabilities và CSP cho response API. HSTS chỉ phát ra khi request được Laravel nhận diện là HTTPS.
- CORS dùng danh sách origin cấu hình, không bật credentials. Nội dung người dùng được React render dưới dạng text.
- API nhận minh chứng ưu đãi học tập ở định dạng JPG, JPEG, PNG hoặc PDF, tối đa 5 MB; tệp được lưu trên private local disk và chỉ tải xuống qua route quản trị đã xác thực. Đây không phải cơ chế quét mã độc; hãy duy trì giới hạn loại/kích thước, quyền truy cập storage và quy trình lưu giữ/xóa dữ liệu phù hợp.
- Checkout dùng transaction/row locks và idempotency; thanh toán trực tuyến chưa kết nối cổng thật, không xử lý webhook thanh toán.
- `.env.example` tắt debug và dùng thông tin DB mẫu có mật khẩu placeholder; secrets thực tế chỉ đặt trong file `.env` không commit.

## Bắt buộc trước khi public

1. Cấp `CHATBOT_SERVICE_TOKEN` là chuỗi ngẫu nhiên tối thiểu 32 byte riêng cho môi trường; dùng cùng giá trị ở Laravel và chatbot. Không đưa token này vào frontend, log, hoặc repository. Thay token sẽ làm capability của các phiên chat hiện có mất hiệu lực.
2. Dùng HTTPS ở reverse proxy/web server cho cả storefront, Laravel API và chatbot; cấu hình trusted proxies chính xác để Laravel nhận diện HTTPS và ứng dụng lấy đúng IP client. Không bật `trust proxy` cho mọi nguồn nếu không kiểm soát mạng phía trước.
3. Đặt `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning` (hoặc chính sách phù hợp), khóa `APP_KEY` riêng, cấu hình `APP_URL` HTTPS và chỉ liệt kê origin production cần thiết trong `CORS_ALLOWED_ORIGINS`/`WEB_ORIGINS`.
4. Tạo tài khoản MySQL riêng, không dùng `root` trong ứng dụng. Tách tài khoản migration có quyền DDL khỏi tài khoản runtime chỉ có quyền cần thiết (`SELECT`, `INSERT`, `UPDATE`, `DELETE` trên database ứng dụng). Hạn chế MySQL bind ra mạng công cộng.
5. Lưu `.env` ngoài web root với quyền đọc tối thiểu; không phục vụ thư mục `api`, `chatbot` hoặc file dump/database từ web server. Không bật directory listing.
6. Cấu hình backup MySQL mã hóa, lưu ngoài máy chủ, kiểm tra khôi phục định kỳ; xoay vòng log và giám sát lỗi/429/đăng nhập thất bại.
7. Cập nhật PHP, Node.js, MySQL/MariaDB và dependencies; chạy `composer audit`, `npm audit` trong quy trình bảo trì và đánh giá từng advisory trước khi deploy.

## Giới hạn

- Rate limiter của chatbot dùng bộ nhớ tiến trình; giới hạn không được chia sẻ giữa nhiều worker/máy và reset khi tiến trình khởi động lại. Đặt reverse proxy/WAF có giới hạn dùng chung hoặc chuyển rate limit sang Redis trước khi chạy nhiều instance.
- Laravel rate limiting dùng cache store đã cấu hình; dùng Redis/shared cache cho nhiều API instance và bảo đảm trusted-proxy/IP configuration chính xác.
- Chatbot mặc định bind `127.0.0.1`; trong container chỉ bind `0.0.0.0` khi cần và giới hạn cổng bằng firewall/network policy để chỉ reverse proxy truy cập.
- Chỉ bật trusted proxy theo dải IP/hop thực tế của load balancer; nếu cấu hình sai, giới hạn theo IP có thể bị vượt hoặc gom mọi khách về một địa chỉ.
- Nội dung chatbot không phải HTML được tin cậy; nếu sau này render rich text/Markdown, phải dùng renderer có sanitize allowlist.
- Triển khai lưu trữ minh chứng trên production, quét mã độc, chính sách lưu giữ/xóa dữ liệu, cổng thanh toán thật, email production và HTTPS chưa được kiểm chứng trong môi trường phát triển này.

## Kiểm tra trước deploy

```powershell
cd api
php artisan config:cache
php artisan route:list
composer audit

cd ..\chatbot
npm audit
npm test

cd ..\web
npm run check
```

Kiểm tra thủ công origin CORS, HTTPS/HSTS, quyền DB runtime, tắt debug, backup/restore và các role customer/admin/staff trên môi trường staging trước khi mở public.
