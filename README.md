# TechStore

TechStore là ứng dụng cửa hàng thiết bị công nghệ.

Ứng dụng gồm giao diện bán hàng, API nghiệp vụ, cổng quản trị, cổng nhân viên và dịch vụ chatbot.

Giao diện người dùng được viết bằng React và TypeScript.

API nghiệp vụ được viết bằng Laravel.

Chatbot là dịch vụ Node.js độc lập.

MySQL lưu danh mục sản phẩm, tài khoản, đơn hàng, khuyến mãi, tồn kho và dữ liệu hỗ trợ.

Laravel là dịch vụ duy nhất kết nối trực tiếp tới MySQL.

Chatbot gọi API nội bộ của Laravel để tìm sản phẩm và lưu cuộc hội thoại.

Trình duyệt không được nhận hoặc lưu bí mật dùng giữa chatbot và Laravel.

Tài liệu này mô tả mã nguồn hiện có trong kho.

Một số tích hợp thanh toán, giao hàng và email vẫn cần cấu hình hoặc xử lý thủ công.

Không xem các ví dụ cấu hình cục bộ là cấu hình an toàn cho môi trường thật.

Trước khi đưa dịch vụ ra Internet, hãy đọc [SECURITY.md](./SECURITY.md).

## Mục lục

1. [Tổng quan](#tổng-quan)
2. [Chức năng](#chức-năng)
3. [Kiến trúc](#kiến-trúc)
4. [Cấu trúc mã nguồn](#cấu-trúc-mã-nguồn)
5. [Công nghệ](#công-nghệ)
6. [Yêu cầu hệ thống](#yêu-cầu-hệ-thống)
7. [Cài đặt trên Windows](#cài-đặt-trên-windows)
8. [Cấu hình môi trường](#cấu-hình-môi-trường)
9. [Cơ sở dữ liệu](#cơ-sở-dữ-liệu)
10. [Hướng dẫn khách hàng](#hướng-dẫn-khách-hàng)
11. [Đơn hàng và thanh toán](#đơn-hàng-và-thanh-toán)
12. [Mã giảm giá và ưu đãi học tập](#mã-giảm-giá-và-ưu-đãi-học-tập)
13. [Cổng quản trị](#cổng-quản-trị)
14. [Cổng nhân viên và chi nhánh](#cổng-nhân-viên-và-chi-nhánh)
15. [Chatbot](#chatbot)
16. [Tham khảo API](#tham-khảo-api)
17. [Nhập dữ liệu sản phẩm](#nhập-dữ-liệu-sản-phẩm)
18. [Lệnh bảo trì](#lệnh-bảo-trì)
19. [Kiểm thử và kiểm tra chất lượng](#kiểm-thử-và-kiểm-tra-chất-lượng)
20. [Triển khai](#triển-khai)
21. [Xử lý sự cố](#xử-lý-sự-cố)
22. [Giới hạn hiện tại](#giới-hạn-hiện-tại)
23. [Thuật ngữ](#thuật-ngữ)

## Tổng quan

TechStore phục vụ việc duyệt, tìm kiếm và mua thiết bị công nghệ.

Khách hàng dùng trang cửa hàng để xem danh mục, quản lý giỏ và đặt hàng.

Khách hàng đã đăng nhập có thể xem lịch sử mua hàng và trạng thái đơn.

Quản trị viên quản lý sản phẩm, mã giảm giá, đơn hàng và các quy trình nội bộ.

Nhân viên và quản lý chi nhánh xử lý các tác vụ được phân quyền.

Chatbot trả lời câu hỏi thường gặp và tìm sản phẩm từ dữ liệu của cửa hàng.

Một số dữ liệu cũ sử dụng tên bảng và cột tiếng Việt.

Các dịch vụ ứng dụng làm việc với cấu trúc dữ liệu đó, không tạo một kho nghiệp vụ thay thế.

### Các địa chỉ khi chạy cục bộ

| Thành phần | Địa chỉ mặc định | Mục đích |
| --- | --- | --- |
| Cửa hàng | `http://localhost:5173/` | Duyệt sản phẩm và mua hàng |
| Quản trị | `http://localhost:5173/admin` | Quản lý cửa hàng |
| Nhân viên | `http://localhost:5173/staff` | Tác vụ theo vai trò và chi nhánh |
| API Laravel | `http://127.0.0.1:8000/api/v1` | API nghiệp vụ |
| Chatbot | `http://127.0.0.1:3001` | Chat và kiểm tra tình trạng dịch vụ |

Các địa chỉ trên có thể thay đổi theo cấu hình máy.

### Quy ước trong tài liệu

Lệnh PowerShell dùng đường dẫn Windows.

Lệnh cần chạy trong một thư mục con sẽ có `cd` rõ ràng.

Các giá trị như `admin@example.test` chỉ là dữ liệu minh họa.

Không dùng mật khẩu ví dụ hoặc bí mật được tạo cho môi trường khác.

Đường dẫn API trong bảng là đường dẫn tương đối.

Để gọi API, ghép đường dẫn tương đối với URL gốc tương ứng.

Các yêu cầu xác thực cần token do đúng luồng đăng nhập phát hành.

## Chức năng

### Cửa hàng và danh mục

- Hiển thị danh mục sản phẩm từ API Laravel.
- Tìm kiếm theo dữ liệu danh mục được lập chỉ mục trong API.
- Lọc theo danh mục và thương hiệu khi có dữ liệu phù hợp.
- Lọc giá, trạng thái khuyến mãi và đánh giá khi có dữ liệu.
- Lọc thông số theo loại thiết bị.
- Sắp xếp theo sản phẩm nổi bật, giá, tên, bán chạy hoặc đánh giá.
- Phân trang kết quả sản phẩm.
- Hiển thị trang chi tiết, giá, tồn kho, hình ảnh và thông số sẵn có.
- Ẩn sản phẩm đã qua sử dụng theo chính sách danh mục.
- Loại trừ một số sản phẩm phụ kiện hoặc đã kích hoạt theo quy tắc danh mục.
- Không cho mua sản phẩm chưa xuất bản, không có giá hợp lệ hoặc hết hàng.
- Thử các hình ảnh khác của cùng sản phẩm khi ảnh hiện tại không tải được.

### Tài khoản và tiện ích khách hàng

- Đăng ký và đăng nhập tài khoản khách hàng.
- Đăng xuất và thu hồi token đăng nhập hiện tại.
- Lưu giỏ hàng phía máy chủ cho khách đã đăng nhập.
- Lưu giỏ khách vãng lai trên trình duyệt.
- Gộp giỏ khách vào tài khoản sau khi đăng nhập.
- Lưu và chọn địa chỉ giao hàng.
- Xem lịch sử mua hàng và chi tiết đơn.
- Theo dõi các thông báo liên quan tới đơn và quy trình.
- Xem tình trạng bảo hành cho sản phẩm đủ điều kiện.
- Viết đánh giá sau khi đáp ứng điều kiện mua hàng.
- Tạo yêu cầu đổi trả cho đơn giao thành công còn trong thời hạn.
- Thu thập mã giảm giá trước khi sử dụng.
- Gửi hồ sơ xin ưu đãi học tập để quản trị viên xét duyệt.

### Đặt hàng

- Tạo đơn từ giỏ đã xác thực ở phía máy chủ.
- Kiểm tra lại giá và số lượng tồn trước khi tạo đơn.
- Lưu bản chụp tên sản phẩm và giá tại thời điểm đặt.
- Tính mã giảm giá và ưu đãi học tập ở máy chủ.
- Chọn một mức giảm hợp lệ cao hơn, không cộng dồn hai loại ưu đãi.
- Nhận khóa idempotency UUID để xử lý gửi lại yêu cầu an toàn.
- Ghi lịch sử thay đổi trạng thái đơn.
- Cho phép khách xem trạng thái và lịch sử đơn trong tài khoản.

### Quản trị và vận hành

- Quản lý sản phẩm, trạng thái xuất bản và thùng rác.
- Quản lý mã giảm giá.
- Xem khách hàng và báo cáo tổng quan.
- Xem đơn, cập nhật các trường được phép và theo dõi giao hàng thủ công.
- Quản lý yêu cầu đổi trả.
- Duyệt hoặc từ chối hồ sơ ưu đãi học tập.
- Quản lý phiếu nhập kho, số sê-ri và bảo hành theo quyền.
- Xem và xử lý hội thoại hỗ trợ.
- Quản lý câu hỏi thường gặp và ví dụ huấn luyện chatbot.
- Tạo chi nhánh qua API quản trị được bảo vệ.
- Quản lý thông tin lương theo quyền được cấp.

### Thanh toán và giao hàng

- Hỗ trợ lựa chọn thanh toán khi nhận hàng.
- Có thể hiển thị mã QR chuyển khoản tĩnh nếu tệp ảnh được cung cấp.
- Việc chuyển khoản cần nhân viên đối soát thủ công.
- Chưa tích hợp cổng thanh toán để xác nhận giao dịch tự động.
- Chưa tự tính phí giao hàng hoặc thời gian giao.
- Nhân viên xác nhận phí và thời gian giao với khách.
- Mã vận đơn được nhập thủ công khi quy trình hỗ trợ.

## Kiến trúc

### Sơ đồ thành phần

```text
Trình duyệt
  ├── React storefront
  ├── React admin portal
  ├── React staff portal
  ├── Laravel JSON API ── MySQL
  └── React chat widget ── Node.js chatbot
                               └── Laravel internal API ── MySQL
```

Giao diện web gọi Laravel cho danh mục, tài khoản, giỏ và nghiệp vụ bán hàng.

Giao diện web gọi chatbot để gửi và nhận tin nhắn.

Chatbot dùng token dịch vụ để gọi các endpoint nội bộ của Laravel.

Chatbot không kết nối trực tiếp đến MySQL.

Token dịch vụ không được đặt trong biến `VITE_` hoặc mã chạy trên trình duyệt.

### Trách nhiệm các thành phần

| Thành phần | Trách nhiệm |
| --- | --- |
| `web` | Giao diện cửa hàng, quản trị, nhân viên và chat |
| `api` | Xác thực, phân quyền, quy tắc nghiệp vụ và truy cập dữ liệu |
| `chatbot` | Phân loại câu hỏi, đề xuất sản phẩm và điều phối chat |
| `database` | Tệp schema cũ dùng làm nguồn tham khảo khi khởi tạo |
| `tools` | Tiện ích xuất dữ liệu sản phẩm ngoại tuyến |

### Giao tiếp và xác thực

Các API Laravel nằm dưới tiền tố `/api/v1`.

API sử dụng JSON cho phần lớn request và response.

Các endpoint khách hàng cần token khách hàng hợp lệ.

Các endpoint quản trị yêu cầu tài khoản quản trị và quyền quản trị.

Các endpoint nhân viên kiểm tra vai trò, và một số thao tác kiểm tra thêm chi nhánh.

Các endpoint chatbot nội bộ yêu cầu token dịch vụ riêng.

Danh sách origin được phép cấu hình riêng cho Laravel và chatbot.

Không dùng CORS thay thế cho xác thực hoặc phân quyền.

## Cấu trúc mã nguồn

```text
TechStore/
├── api/
│   ├── app/
│   │   ├── Console/Commands/
│   │   ├── Http/Controllers/Api/V1/
│   │   ├── Http/Middleware/
│   │   ├── Mail/
│   │   ├── Models/
│   │   └── Services/
│   ├── config/
│   ├── database/
│   │   ├── factories/
│   │   ├── migrations/
│   │   └── seeders/
│   ├── resources/views/
│   ├── routes/
│   └── tests/Feature/
├── chatbot/
│   └── src/server.js
├── database/
│   └── techstore.sql
├── tools/
│   └── export-products.mjs
├── web/
│   ├── public/
│   └── src/
├── README.md
└── SECURITY.md
```

### Thư mục `api`

`api/app/Console/Commands` chứa lệnh Artisan phục vụ quản trị dữ liệu.

`api/app/Http/Controllers/Api/V1` chứa controller cho API phiên bản 1.

`api/app/Http/Middleware` chứa xác thực và kiểm tra quyền truy cập.

`api/app/Mail` chứa các lớp email của ứng dụng.

`api/app/Models` chứa các model Eloquent.

`api/app/Services` chứa logic nghiệp vụ và truy vấn dùng chung.

`api/database/migrations` chứa thay đổi cấu trúc dữ liệu theo phiên bản.

`api/database/seeders` chứa dữ liệu tham chiếu hoặc dữ liệu phục vụ phát triển.

`api/routes/api.php` là nơi khai báo các route API.

`api/tests/Feature` chứa kiểm thử API và quy trình nghiệp vụ.

### Thư mục `web`

`web/src/App.tsx` là điểm vào giao diện cửa hàng.

`web/src/AdminApp.tsx` là giao diện quản trị.

`web/src/StaffApp.tsx` là giao diện nhân viên.

`web/src/CommerceDialogs.tsx` chứa các hộp thoại tài khoản, giỏ và checkout.

`web/src/ChatWidget.tsx` chứa widget hỗ trợ khách hàng.

`web/src/commerce.ts` chứa kiểu dữ liệu và tiện ích gọi API thương mại.

Các tệp CSS trong `web/src` định nghĩa giao diện và bố cục.

### Thư mục `chatbot`

`chatbot/src/server.js` khởi tạo Express và các endpoint chat.

Các bài kiểm thử dùng trình chạy test tích hợp của Node.js.

### Thư mục dữ liệu và công cụ

`database/techstore.sql` là tệp schema lịch sử.

Các migration Laravel mới bổ sung hoặc điều chỉnh schema ứng dụng.

`tools/export-products.mjs` xuất dữ liệu bảng tính sang JSON Lines.

Không chỉnh sửa nguồn dữ liệu gốc bằng các lệnh nhập ảnh.

## Công nghệ

| Lớp | Công nghệ |
| --- | --- |
| Giao diện | React 19 |
| Ngôn ngữ giao diện | TypeScript |
| Bundler | Vite |
| Kiểm thử giao diện | Vitest, Testing Library |
| Kiểm thử trình duyệt | Playwright |
| Lint giao diện | Oxlint |
| API | Laravel 12 |
| Ngôn ngữ API | PHP 8.2 trở lên |
| Cơ sở dữ liệu | MySQL |
| Chatbot | Node.js 20 trở lên, Express 5 |
| Kiểm thử chatbot | Node.js test runner |
| Xuất dữ liệu | Node.js, ExcelJS |

Phiên bản cụ thể được khai báo trong manifest và lock file tương ứng.

Không tự ý nâng phiên bản runtime mà chưa chạy bộ kiểm thử.

## Yêu cầu hệ thống

### Bắt buộc để chạy cục bộ

- Windows, macOS hoặc Linux.
- PHP 8.2 trở lên.
- Composer.
- PHP extension `pdo_mysql`.
- MySQL 8.0.16 trở lên.
- Node.js 20 trở lên.
- npm.
- Các cổng cục bộ chưa bị chương trình khác chiếm.

### Bắt buộc cho kiểm thử trình duyệt

- Đã cài dependencies của `web`.
- Đã cài Chromium qua Playwright nếu máy chưa có.
- Các tiến trình API và frontend cần thiết có thể khởi động.

### Tùy chọn theo tính năng

- SMTP thật nếu cần gửi email ra ngoài.
- Queue worker nếu dùng queue bất đồng bộ.
- Cache dùng chung nếu chạy nhiều instance API.
- Kho lưu trữ bền vững cho tệp tải lên riêng tư.
- Reverse proxy và TLS khi triển khai công khai.

## Cài đặt trên Windows

Thực hiện các bước dưới đây trong PowerShell.

Các cửa sổ terminal riêng có thể cần giữ mở khi chạy dịch vụ.

### Bước 1: Chuẩn bị cơ sở dữ liệu

Khởi động MySQL.

Tạo database có tên `techstore`.

Tạo tài khoản riêng cho ứng dụng.

Không sử dụng tài khoản `root` trong môi trường triển khai.

Tại thư mục gốc dự án, có thể nhập schema lịch sử bằng lệnh:

```powershell
cmd /c "mysql -u techstore_app -p techstore < database\techstore.sql"
```

Lệnh `cmd /c` xử lý chuyển hướng `<` theo cú pháp của Command Prompt.

Không chạy lại tệp schema lên database đang có các bảng trùng tên.

Nếu database đã chứa dữ liệu, hãy sao lưu trước mọi thay đổi.

Schema lịch sử không thay thế các migration Laravel.

### Bước 2: Cài đặt API Laravel

Mở PowerShell tại thư mục gốc dự án.

Chạy các lệnh sau:

```powershell
Set-Location D:\TechStore\api
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
composer install
php artisan key:generate
```

Mở tệp `api/.env`.

Điền cấu hình kết nối MySQL phù hợp với máy.

Đặt `APP_URL` theo địa chỉ API sẽ dùng.

Đặt danh sách origin frontend trong `CORS_ALLOWED_ORIGINS`.

Đặt bí mật dịch vụ chatbot trong biến `CHATBOT_SERVICE_TOKEN`.

Chạy migration:

```powershell
php artisan migrate
```

Tạo symbolic link cho ảnh catalog công khai:

```powershell
php artisan storage:link
```

Khởi động API:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

Giữ cửa sổ terminal này mở trong khi phát triển.

### Bước 3: Cài đặt giao diện web

Mở một cửa sổ PowerShell mới.

Chuyển tới thư mục web:

```powershell
Set-Location D:\TechStore\web
```

Tạo tệp cấu hình nếu chưa có:

```powershell
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
```

Cài dependencies và chạy Vite:

```powershell
npm install
npm run dev
```

Mở địa chỉ được Vite in ra.

Địa chỉ mặc định thường là `http://localhost:5173`.

Frontend sử dụng `VITE_API_URL` và `VITE_CHATBOT_URL`.

### Bước 4: Cài đặt chatbot

Mở một cửa sổ PowerShell khác.

Chuyển tới thư mục chatbot:

```powershell
Set-Location D:\TechStore\chatbot
```

Tạo tệp môi trường cục bộ:

```powershell
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
```

Cài dependencies và khởi động:

```powershell
npm install
npm start
```

Chatbot mặc định lắng nghe tại `127.0.0.1:3001`.

Chatbot cần truy cập được Laravel qua `LARAVEL_API_URL`.

`CHATBOT_SERVICE_TOKEN` phải trùng với giá trị trong `api/.env`.

### Bước 5: Tạo tài khoản quản trị đầu tiên

Trong terminal tại thư mục `api`, chạy:

```powershell
php artisan admin:create admin01 admin@example.test "TechStore Owner"
```

Lệnh tương tác sẽ yêu cầu mật khẩu.

Không truyền mật khẩu bằng tham số dòng lệnh.

Mật khẩu phải có ít nhất 12 ký tự.

Không có endpoint đăng ký quản trị công khai.

Đăng nhập qua trang `http://localhost:5173/admin`.

### Bước 6: Tạo tài khoản nhân viên

Tạo tài khoản nội bộ qua lệnh Artisan:

```powershell
php artisan admin:create nv001 nv001@example.test "Nhan vien 001" --role=nhan_vien --branch=1
```

Quản lý chi nhánh có thể được tạo với vai trò phù hợp:

```powershell
php artisan admin:create gd001 gd001@example.test "Giam doc chi nhanh" --role=giam_doc_chi_nhanh --branch=1
```

Mã chi nhánh phải tồn tại trước khi gán nhân viên.

Trang quản trị hiện chưa có giao diện tạo chi nhánh.

Quản trị viên có thể tạo chi nhánh qua API bảo vệ `POST /api/v1/admin/branches`.

Không sử dụng ID, email hoặc mật khẩu mẫu ở môi trường thật.

### Bước 7: Queue email tùy chọn

Mặc định `QUEUE_CONNECTION=sync`.

Với `sync`, công việc gửi email được thực hiện trong request.

Nếu cấu hình queue database, khởi động worker riêng:

```powershell
Set-Location D:\TechStore\api
php artisan queue:work --tries=3
```

Queue worker không thay thế việc kiểm tra cấu hình SMTP.

### Kiểm tra nhanh sau cài đặt

Mở cửa hàng và kiểm tra frontend tải thành công.

Mở `/health` trên dịch vụ chatbot để kiểm tra tiến trình.

Kiểm tra log Laravel nếu API trả lỗi.

Xác nhận danh mục có dữ liệu và sản phẩm được phép hiển thị.

Đăng nhập admin và kiểm tra quyền truy cập trang quản trị.

Không đặt hàng thử trên database sản xuất.

## Cấu hình môi trường

Không commit `.env` vào hệ thống quản lý phiên bản.

Không chia sẻ nội dung `.env` trong ảnh chụp hoặc nhật ký công khai.

Chỉ lưu cấu hình môi trường phù hợp với từng máy hoặc môi trường triển khai.

### Laravel: `api/.env`

Các biến thường cần kiểm tra:

| Biến | Ý nghĩa |
| --- | --- |
| `APP_ENV` | Môi trường ứng dụng |
| `APP_DEBUG` | Bật/tắt thông tin debug |
| `APP_KEY` | Khóa mã hóa Laravel |
| `APP_URL` | URL gốc của API |
| `DB_CONNECTION` | Driver database |
| `DB_HOST` | Máy chủ MySQL |
| `DB_PORT` | Cổng MySQL |
| `DB_DATABASE` | Tên database |
| `DB_USERNAME` | Tài khoản database |
| `DB_PASSWORD` | Mật khẩu database |
| `CORS_ALLOWED_ORIGINS` | Origin được phép gọi API |
| `CHATBOT_SERVICE_TOKEN` | Bí mật xác thực chatbot nội bộ |
| `EDUCATION_EMAIL_DOMAINS` | Danh sách domain email trường |
| `FILESYSTEM_DISK` | Disk lưu tệp mặc định |
| `QUEUE_CONNECTION` | Driver queue |
| `CACHE_STORE` | Driver cache |
| `MAIL_MAILER` | Driver email |
| `MAIL_HOST` | Máy chủ SMTP |
| `MAIL_PORT` | Cổng SMTP |
| `MAIL_USERNAME` | Tài khoản SMTP |
| `MAIL_PASSWORD` | Mật khẩu SMTP |
| `MAIL_FROM_ADDRESS` | Địa chỉ gửi email |

Ví dụ `.env.example` dùng `MAIL_MAILER=log`.

Mailer `log` không gửi email thật đến hộp thư khách hàng.

### Frontend: `web/.env`

| Biến | Ý nghĩa |
| --- | --- |
| `VITE_API_URL` | URL gốc API Laravel, gồm `/api/v1` |
| `VITE_CHATBOT_URL` | URL gốc dịch vụ chatbot |

Ví dụ cục bộ:

```dotenv
VITE_API_URL=http://127.0.0.1:8000/api/v1
VITE_CHATBOT_URL=http://127.0.0.1:3001
```

Mọi biến có tiền tố `VITE_` có thể được đóng gói vào mã trình duyệt.

Không đặt mật khẩu, token dịch vụ hoặc bí mật khác trong biến `VITE_`.

### Chatbot: `chatbot/.env`

| Biến | Ý nghĩa |
| --- | --- |
| `PORT` | Cổng HTTP chatbot |
| `HOST` | Địa chỉ bind |
| `LARAVEL_API_URL` | URL gốc API Laravel |
| `CHATBOT_SERVICE_TOKEN` | Token dịch vụ trùng với Laravel |
| `WEB_ORIGINS` | Origin frontend được phép |

Ví dụ mặc định có trong `chatbot/.env.example`.

Giữ chatbot ở localhost hoặc mạng nội bộ nếu chưa có reverse proxy an toàn.

### Tạo token ngẫu nhiên

Có thể tạo chuỗi hex ngẫu nhiên bằng PHP:

```powershell
Set-Location D:\TechStore\api
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Đặt cùng giá trị vào `.env` của Laravel và chatbot.

Không gửi token qua kênh công khai.

Không dùng lại token giữa các môi trường.

## Cơ sở dữ liệu

### Khởi tạo schema

Database cục bộ cần database MySQL tên `techstore`.

Tệp schema lịch sử nằm tại `database/techstore.sql`.

Các thay đổi ứng dụng được quản lý tiếp bằng migration Laravel.

Không nhập lại schema vào database đã có các bảng cùng tên.

Sao lưu trước khi nhập schema hoặc chạy migration trên dữ liệu quan trọng.

### Migration quan trọng

Migration tạo cấu trúc mới được lưu trong `api/database/migrations`.

Một số migration nghiệp vụ tiêu biểu:

| Migration | Nội dung chính |
| --- | --- |
| `2026_10_23_000006_create_chatbot_huan_luyen` | Bảng ví dụ hỏi đáp chatbot |
| `2026_10_23_000007_add_catalog_composite_indexes` | Chỉ mục truy vấn danh mục |
| `2026_10_23_000008_create_customer_notifications` | Thông báo khách hàng |
| `2026_10_23_000009_add_support_assignment_and_inventory_history` | Phân công hỗ trợ và lịch sử tồn kho |
| `2026_10_23_000010_add_fulfillment_and_returns` | Trạng thái giao hàng và yêu cầu đổi trả |
| `2026_10_23_000011_add_customer_promotions` | Voucher và yêu cầu ưu đãi học tập |
| `2026_10_23_000012_require_education_discount_review` | Minh chứng và trạng thái xét duyệt học tập |

Tên migration là dấu hiệu tham khảo; hãy kiểm tra tệp thực tế khi triển khai.

Áp dụng migration:

```powershell
Set-Location D:\TechStore\api
php artisan migrate
```

Xem trạng thái migration:

```powershell
php artisan migrate:status
```

### Các bảng nghiệp vụ chính

| Bảng hoặc nhóm | Nội dung |
| --- | --- |
| `san_pham` | Thông tin sản phẩm |
| `san_pham_hinh_anh` | Ảnh và thứ tự ảnh sản phẩm |
| `nguoi_dung` | Tài khoản khách hàng |
| `quan_tri` | Tài khoản quản trị và nhân viên |
| `don_hang` | Đơn hàng |
| `chi_tiet_don_hang` | Dòng sản phẩm và giá chụp tại lúc mua |
| Giỏ hàng | Giỏ được lưu cho tài khoản |
| `dia_chi` | Địa chỉ giao hàng đã lưu |
| `phieu_giam_gia` | Định nghĩa mã giảm giá |
| `voucher_da_thu_thap` | Mã đã được khách thu thập |
| `lich_su_su_dung_voucher` | Lịch sử sử dụng mã |
| `uu_dai_hoc_tap` | Hồ sơ và trạng thái ưu đãi học tập |
| `xac_minh_hoc_tap` | Thử thách OTP email |
| `thong_bao_nguoi_dung` | Thông báo tài khoản |
| `yeu_cau_doi_tra` | Yêu cầu đổi hoặc trả |
| Bảng hội thoại | Hội thoại, tin nhắn và phân công hỗ trợ |
| `chatbot_huan_luyen` | Câu hỏi/đáp án tham khảo chatbot |
| Bảng chi nhánh và tồn kho | Tồn kho, phiếu nhập, điều chỉnh, số sê-ri |

Danh sách trên chỉ mô tả khái niệm, không thay thế schema chi tiết.

### Sửa lỗi thiếu bảng

Nếu lỗi nêu bảng `yeu_cau_doi_tra` không tồn tại, kiểm tra migration fulfillment.

Chạy `php artisan migrate:status` để xác định migration còn thiếu.

Sao lưu dữ liệu trước khi chạy migration cần thiết.

Không tự tạo bảng thủ công để né migration.

Không chạy lại câu lệnh `CREATE TABLE` vào schema đã tồn tại.

## Hướng dẫn khách hàng

### Duyệt và tìm sản phẩm

Trang chủ hiển thị danh mục và các khu vực sản phẩm.

Ô tìm kiếm gửi truy vấn tới API Laravel.

Bộ lọc hiển thị tùy theo dữ liệu và danh mục.

Thông số trống không được coi là thuộc tính đã xác minh.

Bộ lọc laptop không nên áp dụng cho các loại thiết bị không liên quan.

Danh sách kết quả có thể giữ một số bộ lọc trong URL.

Tải lại trang sẽ khôi phục các bộ lọc URL mà giao diện hỗ trợ.

Sản phẩm ở trạng thái bản nháp không thể đặt mua.

Sản phẩm không có giá bán hợp lệ không thể đặt mua.

Sản phẩm hết hàng không thể đặt mua.

### Tìm kiếm, lọc và sắp xếp

Các tham số truy vấn phổ biến gồm `q`, `category` và `brand`.

Có thể lọc giá bằng `min_price` và `max_price`.

Có thể lọc khuyến mãi bằng `on_sale`.

Có thể lọc mức đánh giá bằng `min_rating`.

API có thể nhận giới hạn trang qua `per_page`.

Giá trị sắp xếp gồm `featured`, `price_asc`, `price_desc` và `name`.

Các lựa chọn khác gồm `best_selling` và `top_rated`.

Kết quả lọc phụ thuộc vào mức độ đầy đủ của dữ liệu nhập.

### Yêu thích và so sánh

Danh sách yêu thích được lưu trong local storage của trình duyệt.

Danh sách yêu thích không đồng bộ giữa các tài khoản hoặc thiết bị.

Giao diện so sánh hỗ trợ tối đa bốn sản phẩm.

Thông tin so sánh chỉ gồm các thông số sản phẩm hiện có.

### Giao diện và khả năng truy cập

Giao diện có chế độ sáng và tối.

Giao diện có lựa chọn màu nhấn.

Tùy chọn giao diện được lưu trên trình duyệt hiện tại.

Các thành phần chính có nhãn truy cập.

Các hộp thoại dùng hành vi bàn phím chung của ứng dụng.

Kiểm thử giao diện có một kiểm tra axe-core tập trung.

### Đăng ký và đăng nhập

Khách hàng đăng ký qua hộp thoại tài khoản của cửa hàng.

Đăng ký tạo tài khoản khách và token API.

Mật khẩu được băm bằng cơ chế hashing của Laravel.

Đăng nhập sử dụng thông tin tài khoản theo quy tắc API.

Đăng xuất thu hồi token hiện tại.

Email chào mừng chỉ gửi thật khi cấu hình mailer phù hợp.

### Giỏ hàng khách và giỏ hàng tài khoản

Khách chưa đăng nhập có thể lưu giỏ cục bộ trên trình duyệt.

Giỏ cục bộ có thể mất khi dữ liệu trình duyệt bị xóa.

Khách đã đăng nhập dùng giỏ được lưu qua Laravel.

Khi đăng nhập, giao diện có thể gộp giỏ cục bộ vào giỏ tài khoản.

API kiểm tra số lượng cho phép khi gộp giỏ.

Máy chủ là nguồn xác thực cuối cùng cho giá và khả năng mua.

Không tin giá hoặc tổng tiền do trình duyệt gửi lên khi thanh toán.

### Địa chỉ giao hàng

Khách hàng có thể lưu địa chỉ theo nhãn.

Địa chỉ có thể bao gồm tên người nhận, số điện thoại và địa chỉ.

Địa chỉ chi tiết có thể gồm phường, quận và thành phố.

Khách có thể chọn địa chỉ đã lưu khi checkout.

Khách có thể xóa địa chỉ đã lưu.

Thông tin giao hàng được chụp vào đơn ở thời điểm tạo.

Khu vực giao hàng lưu trong trình duyệt không tự tính phí ship.

Khu vực giao hàng hiện không lọc danh sách sản phẩm.

## Đơn hàng và thanh toán

### Tạo đơn

Khách phải đăng nhập để gửi đơn hàng.

Checkout yêu cầu người nhận, số điện thoại và địa chỉ giao hàng.

Checkout yêu cầu lựa chọn phương thức thanh toán.

Request tạo đơn yêu cầu header UUID `Idempotency-Key`.

Cùng một khóa idempotency được dùng để xử lý gửi lại cùng request.

Máy chủ kiểm tra lại tồn và giá trước khi tạo đơn.

Thông tin sản phẩm và giá được chụp vào từng dòng đơn.

Tổng tiền và mức giảm được tính từ dữ liệu phía máy chủ.

Các thao tác đơn hàng quan trọng nằm trong transaction khi phù hợp.

Tồn kho được khóa theo quy trình cần thiết để tránh bán vượt số lượng.

Phí giao hàng chưa được tự động cộng vào tổng checkout hiện tại.

### Trạng thái đơn và lịch sử mua hàng

Khách đã đăng nhập mở **Lịch sử mua hàng** từ phần tài khoản trên trang cửa hàng.

Danh sách đơn hiển thị trạng thái hiện tại và thông tin sẵn có.

Chi tiết đơn hiển thị dòng hàng và dữ liệu thanh toán/giao hàng nếu có.

Các lần đổi trạng thái được lưu trong lịch sử trạng thái.

API kiểm tra chuyển trạng thái hợp lệ trước khi cập nhật.

Các trạng thái nghiệp vụ hiện có gồm chờ xác nhận, đang giao và đã giao.

Đơn có thể bị hủy theo các điều kiện trạng thái và thanh toán.

Đơn đã thanh toán cần quy trình hoàn tiền phía cửa hàng trước khi hủy.

Mã hãng vận chuyển và mã vận đơn được nhập thủ công nếu có.

Thông báo tài khoản có thể ghi nhận cập nhật đơn hoặc quy trình.

### Lựa chọn thanh toán

Thanh toán khi nhận hàng là một lựa chọn được hỗ trợ.

Chuyển khoản là quy trình xác nhận thủ công.

Ảnh QR MoMo hoặc Agribank nếu có chỉ là ảnh tĩnh.

Ảnh QR không kết nối với cổng thanh toán.

Không có webhook thanh toán trong ứng dụng hiện tại.

Hệ thống không thể tự xác nhận tiền đã vào tài khoản.

Nhân viên phải kiểm tra giao dịch thực trước khi xử lý đơn chuyển khoản.

Không coi thông báo từ trình duyệt là bằng chứng giao dịch thành công.

### Đánh giá sản phẩm

Khách có thể đọc đánh giá từ endpoint sản phẩm công khai.

Chỉ khách đáp ứng điều kiện đã mua và đã nhận hàng mới được đánh giá.

API xác minh quyền đánh giá trước khi lưu.

Tổng quan điểm đánh giá phụ thuộc dữ liệu đánh giá hiện có.

### Bảo hành

Khách có thể tra cứu bảo hành cho sản phẩm đủ điều kiện.

Thời hạn bảo hành phụ thuộc dữ liệu sản phẩm hoặc số sê-ri.

Không tự suy đoán thời hạn khi dữ liệu không có.

Quản trị viên hoặc nhân viên được phép có thể cập nhật trạng thái bảo hành.

Một số thay đổi bảo hành có thể phát sinh thông báo khách hàng.

### Đổi trả

Yêu cầu đổi trả gắn với một đơn hàng của khách đã đăng nhập.

Đơn phải ở trạng thái đã giao.

Mỗi đơn chỉ có tối đa một yêu cầu đổi trả theo quy tắc hiện tại.

Yêu cầu phải được tạo trong vòng bảy ngày tính từ thời điểm giao.

Khách gửi lý do và thông tin mô tả nếu cần.

Quản trị viên xem và cập nhật quyết định qua giao diện quản trị.

Quy trình có thể hỗ trợ phê duyệt, từ chối và hoàn tất.

Ghi chú xử lý có thể gửi tới khách qua thông báo.

Yêu cầu chỉ ghi nhận và theo dõi quyết định.

Yêu cầu không tự tạo vận đơn đổi trả.

Yêu cầu không tự hoàn tiền.

## Mã giảm giá và ưu đãi học tập

### Vòng đời mã giảm giá

Quản trị viên tạo mã giảm giá trong trang quản trị.

Mã có thể giảm theo phần trăm hoặc một khoản cố định.

Mã có thể yêu cầu giá trị đơn tối thiểu.

Mã phần trăm có thể đặt mức giảm tối đa.

Mã có thể có thời điểm bắt đầu và hết hạn.

Mã có thể giới hạn tổng lượt sử dụng.

Quản trị viên có thể bật hoặc tắt mã.

Khách đã đăng nhập xem các mã hiện có.

Khách thu thập mã trước khi dùng theo workflow ứng dụng.

API kiểm tra điều kiện mã theo dữ liệu checkout hiện tại.

Mã được ghi nhận sử dụng khi tạo đơn thành công.

Theo quy tắc hiện tại, mỗi khách chỉ sử dụng một lần cho mỗi mã.

Việc hủy đơn đủ điều kiện có thể hoàn lại lượt dùng mã.

### Mức giảm ưu đãi học tập

Ưu đãi áp dụng cho các nhóm học sinh, sinh viên và giáo viên được cấu hình.

Các mức phần trăm hiện được định nghĩa như sau:

| Nhóm | Mức giảm |
| --- | --- |
| Học sinh THCS/THPT | 5% |
| Sinh viên cao đẳng | 7% |
| Sinh viên đại học | 9% |
| Học viên thạc sĩ | 11% |
| Nghiên cứu sinh tiến sĩ | 13% |
| Giáo viên | 15% |
| Giảng viên cao đẳng | 17% |
| Giảng viên đại học | 20% |

Mức giảm tối đa cho một đơn là 2.000.000 VND.

Ưu đãi học tập không cộng dồn với mã giảm giá.

Checkout chọn mức giảm hợp lệ có lợi hơn cho đơn.

### Gửi và duyệt hồ sơ học tập

Khách đăng nhập và mở khu vực ưu đãi học tập.

Khách chọn nhóm đối tượng xin ưu đãi.

Khách nhập email thuộc domain trường được cấu hình.

API gửi mã OTP tới địa chỉ email đó.

OTP có thời hạn mười phút.

Khách chuẩn bị minh chứng ở định dạng JPG, JPEG, PNG hoặc PDF.

Dung lượng tệp tối đa là 5 MB.

Khách gửi OTP cùng minh chứng.

Minh chứng được lưu trong private local storage.

Sau khi xác minh OTP, hồ sơ chuyển sang chờ quản trị viên xem xét.

Xác minh OTP không tự kích hoạt giảm giá.

Quản trị viên mở mục **Duyệt ưu đãi HSSV–GV**.

Quản trị viên tải minh chứng qua endpoint được xác thực.

Quản trị viên chấp thuận hoặc từ chối hồ sơ.

Từ chối yêu cầu ghi chú và cho phép khách gửi hồ sơ lại.

Chỉ hồ sơ được chấp thuận mới được dùng mức giảm tương ứng.

Khách nhận trạng thái hồ sơ qua giao diện và thông báo liên quan.

OTP chứng minh quyền truy cập email, không thay thế việc kiểm tra minh chứng.

Chỉ endpoint quản trị được phép tải tệp minh chứng riêng tư.

Không chuyển tệp minh chứng sang thư mục công khai để xử lý lỗi tải.

### Hồ sơ cũ và migration

Migration duyệt thêm trạng thái và đường dẫn minh chứng.

Migration lưu người duyệt, thời điểm và ghi chú xét duyệt.

Hồ sơ cũ không có minh chứng không được tự coi là đã duyệt.

Hồ sơ thiếu minh chứng được chuyển sang trạng thái cần gửi lại.

Hãy kiểm tra migration đã chạy trước khi kiểm thử quy trình này.

## Cổng quản trị

### Đăng nhập và quyền hạn

Mở `/admin` trên ứng dụng web.

Đăng nhập bằng tài khoản quản trị nội bộ.

Các API quản trị cần token hợp lệ và quyền tương ứng.

Tài khoản quản trị được tạo bằng lệnh nội bộ hoặc quy trình được cấp quyền.

Không có endpoint đăng ký quản trị công khai.

Tên vai trò trên giao diện không thay thế kiểm tra quyền ở API.

### Quản lý sản phẩm

Quản trị viên có thể tạo sản phẩm.

Quản trị viên có thể chỉnh sửa thông tin sản phẩm.

Thông tin có thể gồm tên, slug, thương hiệu và danh mục.

Thông tin có thể gồm giá bán, giá niêm yết và tồn kho.

Thông số kỹ thuật được lưu cùng sản phẩm.

Sản phẩm có thể ở trạng thái bản nháp hoặc đã xuất bản.

Sản phẩm có thể được chuyển vào thùng rác.

Sản phẩm có thể được khôi phục nếu chưa bị xóa vĩnh viễn.

Xóa vĩnh viễn được bảo vệ khi có dữ liệu lịch sử cần giữ.

### Mã giảm giá và báo cáo

Quản trị viên có thể xem và chỉnh sửa mã giảm giá.

Quản trị viên có thể xem báo cáo tổng quan hiện có.

Báo cáo phụ thuộc dữ liệu đơn hàng và trạng thái được lưu.

Không xem báo cáo nội bộ là số liệu đã đối soát kế toán.

### Đơn hàng và yêu cầu đổi trả

Quản trị viên có thể xem danh sách và chi tiết đơn.

API chỉ cho phép cập nhật các trường được chấp nhận.

Thay đổi trạng thái phải theo luồng hợp lệ.

Thông tin hãng vận chuyển và mã vận đơn có thể được nhập thủ công.

Quản trị viên có thể xem các yêu cầu đổi trả.

Quản trị viên có thể cập nhật trạng thái và ghi chú xử lý.

### Duyệt ưu đãi học tập

Quản trị viên xem danh sách hồ sơ học tập.

Quản trị viên tải minh chứng bằng endpoint đã xác thực.

Quản trị viên kiểm tra nhóm đối tượng và giấy tờ minh chứng.

Hồ sơ chỉ được duyệt khi trạng thái và tệp hợp lệ.

Quyết định được lưu cùng tài khoản và thời điểm duyệt.

Không gửi tệp minh chứng qua chat công khai hoặc email không bảo vệ.

### Hỗ trợ khách hàng

Quản trị viên có thể xem hội thoại hỗ trợ.

Quản trị viên có thể phân công nhân viên hỗ trợ.

Quản trị viên có thể trả lời hoặc cập nhật hội thoại.

Tin nhắn được lưu qua Laravel.

### Câu hỏi thường gặp chatbot

Quản trị viên quản lý ví dụ hỏi đáp được hỗ trợ.

Ví dụ có thể được thêm, chỉnh sửa, bật/tắt hoặc xóa.

Điểm khớp thấp không được coi là câu trả lời chắc chắn.

Đây là cơ chế khớp FAQ, không phải mô hình tự học từ hội thoại.

## Cổng nhân viên và chi nhánh

### Vai trò

Các vai trò nội bộ được kiểm tra bởi middleware và logic API.

Tên role chính xác cần đối chiếu với tài khoản đã được cấp.

Vai trò thường gặp gồm chủ cửa hàng, quản lý chi nhánh và nhân viên.

Vai trò cấp công ty có thể xem phạm vi doanh thu rộng hơn.

Không cấp quyền chỉ dựa trên việc ẩn/hiện nút giao diện.

### Nhân viên

Nhân viên đăng nhập tại `/staff`.

Nhân viên có thể xem hàng đợi đơn đủ điều kiện.

Nhân viên có thể nhận xử lý đơn chưa được phân công nếu quyền cho phép.

API từ chối nhận đơn đã được nhân viên hoặc chi nhánh khác sở hữu.

Nhân viên có thể xem tồn kho theo phạm vi được cấp.

Nhân viên có thể xem lịch sử điều chỉnh tồn kho.

Nhân viên có thể xử lý hội thoại hỗ trợ được phân công.

Nhân viên có thể xem dữ liệu lương cá nhân nếu vai trò cho phép.

### Quản lý chi nhánh

Quản lý chi nhánh cần được gán chi nhánh hợp lệ.

Tồn kho và lịch sử tồn kho được giới hạn theo chi nhánh.

Ngưỡng cảnh báo tồn thấp hiện là không quá năm sản phẩm.

Quản lý được phép thực hiện một số tác vụ nhập kho và bảo hành.

Một số thao tác báo lỗi nếu tài khoản chưa được gán chi nhánh.

### Chi nhánh

API quản trị hỗ trợ tạo và xem chi nhánh.

Trang quản trị hiện không cung cấp nút tạo chi nhánh.

Chi nhánh có thể được tạo qua `POST /api/v1/admin/branches`.

Endpoint tạo chi nhánh cần token admin hợp lệ.

Hãy xác thực dữ liệu chi nhánh và người được phân quyền trước khi tạo.

### Doanh thu và KPI

Phạm vi số liệu phụ thuộc vai trò và chi nhánh.

KPI nhân viên dựa trên các đơn phù hợp được giao và đã giao.

Quản lý và chủ cửa hàng có phạm vi dữ liệu rộng hơn.

Không đối chiếu doanh thu KPI với thanh toán thực tế nếu chưa xác nhận giao dịch.

### Lương

Nhân viên có thể đọc dữ liệu lương cá nhân nếu được cấp quyền.

Quản trị viên có thể cập nhật dữ liệu lương theo kỳ.

Thông tin có thể gồm lương cơ bản, phụ cấp, thưởng và khấu trừ.

API kiểm tra giá trị và kỳ lương trước khi lưu.

## Chatbot

### Khả năng

- Phân loại một số nhóm câu hỏi phổ biến bằng luật và từ khóa.
- Nhận diện câu hỏi về thanh toán, đơn hàng, giao hàng và bảo hành.
- Tìm sản phẩm từ API danh mục Laravel.
- Nhận diện nhóm sản phẩm, ngân sách và mục đích sử dụng.
- Đưa ra tối đa ba gợi ý sản phẩm phù hợp dữ liệu.
- Duy trì hội thoại trong phiên.
- Lưu nội dung hội thoại qua API Laravel.
- Hỗ trợ yêu cầu chuyển tiếp tới nhân viên.
- Cho phép quản trị viên cập nhật FAQ và ví dụ huấn luyện.

### Nhận diện nhu cầu chơi game

Chatbot nhận diện các cụm từ liên quan chơi game.

Chatbot cũng nhận diện một số cách nói về GPU hoặc card đồ họa rời.

Khi phù hợp, chatbot gọi API catalog với bộ lọc gaming.

Catalog áp dụng tiêu chí gaming và các dòng GPU/model nhận diện được.

Nếu không tìm thấy thiết bị phù hợp, chatbot thông báo không có kết quả.

Chatbot không nên tự thay bằng laptop văn phòng khi người dùng yêu cầu gaming.

Chất lượng gợi ý phụ thuộc thông số được nhập trong catalog.

### FAQ và câu trả lời

FAQ được lưu trong bảng `chatbot_huan_luyen`.

Khớp FAQ dựa trên từ khóa và điểm tương đồng được cấu hình.

Nếu điểm khớp dưới ngưỡng, câu trả lời không được sử dụng như kết luận chắc chắn.

Chatbot không tự huấn luyện mô hình từ nội dung hội thoại khách.

Khi chính sách cửa hàng thay đổi, hãy cập nhật nội dung FAQ tương ứng.

Seeder FAQ tùy chọn có thể chạy bằng lệnh:

```powershell
Set-Location D:\TechStore\api
php artisan db:seed --class=Database\Seeders\ChatbotKnowledgeSeeder
```

Kiểm tra seed class tồn tại trong phiên bản mã nguồn đang dùng.

### Chuyển tiếp tới nhân viên

Khách có thể yêu cầu gặp nhân viên.

Hội thoại được đánh dấu chờ hỗ trợ.

Quản trị viên có thể xem, phân công và trả lời.

Nhân viên được phân công có thể trả lời theo quyền.

Widget tải lại nội dung để hiển thị tin nhắn mới.

Khách nên đăng nhập và dùng lịch sử mua hàng để xem dữ liệu đơn riêng.

Chatbot không tra cứu dữ liệu đơn riêng cho khách ẩn danh.

### Giới hạn và bảo mật chatbot

Endpoint công khai gồm `GET /health`, `POST /api/chat` và lịch sử hội thoại.

Truy cập lịch sử hội thoại cần capability đã ký.

Capability hội thoại hết hạn sau 30 ngày.

Nội dung JSON đầu vào có giới hạn kích thước.

Tin nhắn có giới hạn độ dài phía dịch vụ.

Chatbot áp dụng giới hạn tốc độ theo IP trong tiến trình.

Giới hạn trong bộ nhớ không được chia sẻ giữa nhiều instance.

Yêu cầu tới Laravel có timeout để tránh chờ vô hạn.

Khi Laravel không khả dụng, chatbot trả lỗi thay vì tạo kết quả giả.

Trước khi mở rộng nhiều instance, cần rate limit dùng chung ở gateway.

## Tham khảo API

### Quy ước chung

URL gốc cục bộ của Laravel là `http://127.0.0.1:8000/api/v1`.

Route được khai báo trong `api/routes/api.php`.

Phần lớn endpoint trả JSON.

Endpoint danh sách có thể trả phân trang.

Lỗi xác thực thường trả HTTP 401.

Thiếu quyền thường trả HTTP 403.

Lỗi dữ liệu đầu vào trả lỗi validation.

Trạng thái nghiệp vụ không phù hợp có thể trả lỗi xung đột hoặc validation.

Luôn đọc nội dung lỗi của API thay vì chỉ dựa vào mã trạng thái.

### Header xác thực

Request đã đăng nhập dùng header:

```http
Authorization: Bearer <access-token>
Accept: application/json
```

Token phải được lấy từ đúng endpoint đăng nhập.

Token khách hàng không dùng thay token admin hoặc staff.

Không ghi token thật vào README, log hoặc ảnh chụp.

### Đăng ký và đăng nhập

| Phương thức | Đường dẫn | Mục đích |
| --- | --- | --- |
| `POST` | `/auth/register` | Tạo tài khoản khách |
| `POST` | `/auth/login` | Đăng nhập khách |
| `POST` | `/auth/admin/login` | Đăng nhập quản trị |
| `POST` | `/auth/staff/login` | Đăng nhập nhân viên |
| `POST` | `/auth/logout` | Thu hồi token người dùng |
| `POST` | `/auth/admin/logout` | Đăng xuất quản trị |

Đăng ký và đăng nhập có giới hạn tần suất.

Không có route tạo admin công khai.

### Danh mục công khai

| Phương thức | Đường dẫn | Mục đích |
| --- | --- | --- |
| `GET` | `/categories` | Danh sách danh mục |
| `GET` | `/brands` | Danh sách thương hiệu |
| `GET` | `/filters` | Bộ lọc theo danh mục |
| `GET` | `/products` | Tìm, lọc, sắp xếp sản phẩm |
| `GET` | `/products/{slug}` | Chi tiết sản phẩm |
| `GET` | `/products/{slug}/reviews` | Đánh giá sản phẩm |

Danh mục công khai có giới hạn bổ sung 120 request mỗi phút.

Tham số thường dùng gồm `q`, `category`, `brand`, `min_price` và `max_price`.

Các tham số khác gồm `on_sale`, `min_rating`, `sort` và `per_page`.

Các giá trị sort gồm `featured`, `price_asc`, `price_desc`, `name`.

Các lựa chọn còn lại là `best_selling` và `top_rated`.

Chi tiết validation nằm trong controller và service liên quan.

### Giỏ, địa chỉ và voucher khách hàng

| Phương thức | Đường dẫn | Mục đích |
| --- | --- | --- |
| `GET` | `/cart` | Xem giỏ hàng |
| `POST` | `/cart/merge` | Gộp giỏ cục bộ |
| `POST` | `/cart/items` | Thêm sản phẩm |
| `PATCH` | `/cart/items/{productId}` | Cập nhật số lượng |
| `DELETE` | `/cart/items/{productId}` | Xóa sản phẩm |
| `GET` | `/addresses` | Xem địa chỉ |
| `POST` | `/addresses` | Lưu địa chỉ |
| `DELETE` | `/addresses/{label}` | Xóa địa chỉ |
| `POST` | `/vouchers/validate` | Kiểm tra voucher |
| `GET` | `/vouchers` | Xem voucher khả dụng |
| `POST` | `/vouchers/{code}/claim` | Thu thập voucher |

Các endpoint này cần token khách hàng.

### Ưu đãi học tập

| Phương thức | Đường dẫn | Mục đích |
| --- | --- | --- |
| `GET` | `/education-discount` | Xem trạng thái hồ sơ |
| `POST` | `/education-discount/request-code` | Gửi OTP |
| `POST` | `/education-discount/verify-code` | Xác minh OTP và hồ sơ |

Yêu cầu gửi OTP và xác minh có throttling riêng.

Request gửi hồ sơ dùng multipart để đính kèm minh chứng.

Các định dạng tệp được chấp nhận hiện gồm JPG, JPEG, PNG và PDF.

Dung lượng minh chứng tối đa 5 MB.

Các endpoint này cần token khách hàng.

### Đơn hàng và tiện ích khách

| Phương thức | Đường dẫn | Mục đích |
| --- | --- | --- |
| `POST` | `/orders` | Checkout |
| `GET` | `/orders` | Lịch sử mua hàng |
| `GET` | `/orders/{orderId}` | Chi tiết đơn |
| `POST` | `/orders/{orderId}/cancel` | Yêu cầu hủy đơn |
| `GET` | `/returns` | Danh sách yêu cầu đổi trả |
| `POST` | `/orders/{orderId}/returns` | Tạo yêu cầu đổi trả |
| `GET` | `/warranties` | Tra cứu bảo hành |
| `GET` | `/notifications` | Xem thông báo |
| `PATCH` | `/notifications/read-all` | Đánh dấu đã đọc |
| `PATCH` | `/notifications/{notificationId}/read` | Đánh dấu một thông báo |
| `POST` | `/products/{productId}/reviews` | Gửi đánh giá |

Các endpoint này yêu cầu tài khoản khách hàng phù hợp.

Request checkout phải gửi header UUID `Idempotency-Key`.

### Endpoint quản trị

Các route dưới đây nằm dưới tiền tố `/admin`.

Toàn bộ route quản trị yêu cầu bearer token và quyền admin.

| Phương thức | Đường dẫn | Mục đích |
| --- | --- | --- |
| `GET` | `/products` | Danh sách sản phẩm |
| `GET` | `/products/trash` | Sản phẩm trong thùng rác |
| `POST` | `/products` | Tạo sản phẩm |
| `PATCH` | `/products/{productId}` | Cập nhật sản phẩm |
| `DELETE` | `/products/{productId}` | Xóa mềm sản phẩm |
| `POST` | `/products/{productId}/restore` | Khôi phục sản phẩm |
| `DELETE` | `/products/{productId}/force` | Xóa vĩnh viễn có kiểm tra |
| `GET` | `/vouchers` | Danh sách voucher |
| `POST` | `/vouchers` | Tạo voucher |
| `PATCH` | `/vouchers/{code}` | Cập nhật voucher |
| `GET` | `/stock-receipts` | Xem phiếu nhập |
| `POST` | `/stock-receipts` | Tạo phiếu nhập |
| `GET` | `/warranties` | Danh sách bảo hành |
| `GET` | `/customers` | Tìm khách hàng |
| `GET` | `/branches` | Danh sách chi nhánh |
| `POST` | `/branches` | Tạo chi nhánh |
| `PUT` | `/payroll/{employeeId}/{year}/{month}` | Cập nhật lương |
| `GET` | `/orders` | Danh sách đơn |
| `GET` | `/orders/{orderId}` | Chi tiết đơn |
| `PATCH` | `/orders/{orderId}` | Cập nhật trường cho phép |
| `GET` | `/reports` | Báo cáo tổng quan |
| `GET` | `/returns` | Danh sách yêu cầu đổi trả |
| `PATCH` | `/returns/{requestId}` | Xử lý yêu cầu đổi trả |
| `GET` | `/education-discounts` | Danh sách hồ sơ học tập |
| `GET` | `/education-discounts/{customerId}/evidence` | Tải minh chứng riêng tư |
| `PATCH` | `/education-discounts/{customerId}` | Duyệt hồ sơ học tập |
| `GET` | `/conversations` | Danh sách hội thoại |
| `GET` | `/support-staff` | Nhân viên hỗ trợ |
| `GET` | `/conversations/{conversationId}` | Chi tiết hội thoại |
| `PATCH` | `/conversations/{conversationId}` | Phân công hoặc trả lời |
| `GET` | `/chatbot-training` | Danh sách FAQ |
| `POST` | `/chatbot-training` | Tạo FAQ |
| `PATCH` | `/chatbot-training/{id}` | Cập nhật FAQ |
| `DELETE` | `/chatbot-training/{id}` | Xóa FAQ |

Một số danh sách hỗ trợ phân trang và bộ lọc.

Luôn tham khảo quy tắc validation của endpoint trước khi tích hợp.

### Endpoint nhân viên

Các route sau nằm dưới tiền tố `/staff`.

Quyền cụ thể còn phụ thuộc middleware theo vai trò.

| Phương thức | Đường dẫn | Mục đích |
| --- | --- | --- |
| `POST` | `/auth/logout` | Đăng xuất nhân viên |
| `GET` | `/revenue` | Doanh thu/KPI theo quyền |
| `GET` | `/conversations` | Hội thoại hỗ trợ |
| `GET` | `/conversations/{conversationId}` | Chi tiết hội thoại |
| `PATCH` | `/conversations/{conversationId}` | Cập nhật hoặc trả lời |
| `GET` | `/payroll` | Lương cá nhân |
| `PATCH` | `/warranties/{serialNumber}` | Cập nhật bảo hành |
| `GET` | `/inventory` | Tồn kho chi nhánh |
| `GET` | `/inventory/history` | Lịch sử tồn kho |
| `GET` | `/orders` | Hàng đợi đơn |
| `POST` | `/orders/{orderId}/claim` | Nhận xử lý đơn |
| `PATCH` | `/products/{productId}/stock` | Điều chỉnh tồn |
| `GET` | `/products` | Danh sách sản phẩm theo quyền |
| `POST` | `/products` | Tạo sản phẩm nếu được phép |
| `PATCH` | `/products/{productId}` | Sửa sản phẩm nếu được phép |
| `DELETE` | `/products/{productId}` | Xóa mềm nếu được phép |
| `GET` | `/stock-receipts` | Xem phiếu nhập nếu được phép |
| `POST` | `/stock-receipts` | Tạo phiếu nhập nếu được phép |

### Endpoint nội bộ chatbot

Các route sau nằm dưới tiền tố `/internal/chatbot`.

Các route này yêu cầu token dịch vụ và có giới hạn tần suất.

| Phương thức | Đường dẫn | Mục đích |
| --- | --- | --- |
| `GET` | `/knowledge/match?q=...` | Khớp câu hỏi với FAQ |
| `GET` | `/knowledge/{intent}` | Lấy nội dung theo intent |
| `GET` | `/products` | Tìm sản phẩm cho chatbot |
| `POST` | `/conversations` | Tạo hoặc lấy hội thoại |
| `POST` | `/conversations/{conversationId}/messages` | Lưu tin nhắn |
| `POST` | `/conversations/{conversationId}/handoff` | Chuyển nhân viên |
| `GET` | `/conversations/{conversationId}` | Đọc lịch sử nội bộ |

Không gọi endpoint nội bộ trực tiếp từ trình duyệt.

### Endpoint công khai chatbot

| Phương thức | Đường dẫn | Mục đích |
| --- | --- | --- |
| `GET` | `/health` | Kiểm tra tiến trình |
| `POST` | `/api/chat` | Gửi tin nhắn |
| `GET` | `/api/chat/{conversationId}` | Đọc lịch sử bằng capability |

Các endpoint này do Node.js phục vụ, không thuộc Laravel.

## Nhập dữ liệu sản phẩm

### Nguyên tắc dữ liệu

Kiểm tra dữ liệu nhập trước khi xuất bản sản phẩm.

Đối chiếu tên, danh mục, thương hiệu, giá, tồn kho và thông số.

Không tự tạo giá bán khi nguồn không có dữ liệu đáng tin cậy.

Không dùng hình ảnh của sản phẩm khác để lấp ảnh thiếu.

Chỉ sử dụng dữ liệu và hình ảnh có quyền sử dụng phù hợp.

Sản phẩm nhập mới được xử lý theo workflow bản nháp.

### Nhập danh mục JSON Lines

Lệnh Artisan nhận tệp JSONL:

```powershell
Set-Location D:\TechStore\api
php artisan catalog:import "D:\imports\cellphones-products.jsonl"
```

Mỗi dòng là một đối tượng JSON độc lập.

Các trường bắt buộc gồm URL sản phẩm, tên và danh mục hợp lệ.

Trường tùy chọn có thể gồm thương hiệu, giá, mô tả và thông số.

Lệnh báo cáo các dòng không hợp lệ đã bỏ qua.

Kiểm tra kết quả sau khi nhập trước khi xuất bản.

### Xuất bảng tính sang JSONL

Công cụ xuất đọc worksheet `Sản phẩm`.

Các cột bắt buộc gồm `URL`, `Ngành hàng` và `Tên sản phẩm`.

Cài dependencies của công cụ nếu chưa được cài:

```powershell
Set-Location D:\TechStore\tools
npm install
```

Chạy công cụ với đường dẫn tệp nguồn và đích:

```powershell
npm run export-products -- "D:\imports\cellphones_product_details_final.xlsx" "D:\TechStore\api\storage\app\imports\cellphones-products.jsonl"
```

Kiểm tra JSONL và giá trước khi nhập database không phải môi trường thử nghiệm.

### Nhập hình ảnh

Ảnh được ghép với sản phẩm bằng slug từ URL sản phẩm.

Tên thư mục nguồn cần khớp slug đó.

Định dạng hỗ trợ gồm JPG, JPEG, PNG và WebP.

Lệnh sao chép ảnh vào public storage Laravel.

Lệnh không sửa hoặc xóa thư mục nguồn.

Thứ tự tên tệp được sắp xếp tự nhiên để ổn định giữa các lần chạy.

Một số ảnh placeholder lặp đã biết có thể bị bỏ qua.

Liên kết ảnh được cập nhật để tránh tạo dòng trùng khi chạy lại.

Lệnh báo cáo thư mục không khớp hoặc sản phẩm thiếu ảnh.

Chạy nhập ảnh:

```powershell
Set-Location D:\TechStore\api
php artisan catalog:import-images "D:\imports\product_images"
```

### Tối ưu ảnh

Web có script tạo biến thể WebP.

Script tạo kích thước 320, 480 và 960 pixel.

Ảnh nguồn được giữ nguyên.

Laravel chỉ trả biến thể responsive khi tệp đó tồn tại.

Chạy trong thư mục `web`:

```powershell
Set-Location D:\TechStore\web
npm run images:optimize
```

Kiểm tra chất lượng hiển thị và quyền sử dụng trước khi xuất bản ảnh.

### Đồng bộ giá

Lệnh đồng bộ giá có chế độ xem trước trước khi ghi.

Chỉ xử lý URL nguồn thuộc domain được cấu hình cho phép.

Lệnh đọc dữ liệu giá có cấu trúc từ trang sản phẩm tương ứng.

Không suy đoán giá khi không tìm thấy dữ liệu đáng tin cậy.

Đồng bộ giá không tự xuất bản sản phẩm.

Xem trước tối đa mười sản phẩm:

```powershell
Set-Location D:\TechStore\api
php artisan catalog:sync-prices --limit=10
```

Áp dụng sau khi kiểm tra:

```powershell
php artisan catalog:sync-prices --limit=10 --apply
```

Chỉ dùng `--all` khi hiểu rõ khối lượng request và chính sách nguồn.

Tôn trọng độ trễ giữa các request tới nguồn bên ngoài.

### Xuất bản sản phẩm đã có giá

Lệnh `catalog:publish-priced` mặc định xem trước sản phẩm đủ điều kiện.

Chạy xem trước:

```powershell
Set-Location D:\TechStore\api
php artisan catalog:publish-priced
```

Đọc danh sách ứng viên trước khi dùng tùy chọn áp dụng.

Chỉ xuất bản sản phẩm đã được kiểm tra thông tin.

## Lệnh bảo trì

### Laravel

| Lệnh | Mục đích |
| --- | --- |
| `php artisan migrate` | Áp dụng migration còn thiếu |
| `php artisan migrate:status` | Xem trạng thái migration |
| `php artisan storage:link` | Tạo liên kết storage công khai |
| `php artisan queue:work --tries=3` | Chạy queue worker |
| `php artisan admin:create` | Tạo tài khoản nội bộ |
| `php artisan catalog:import` | Nhập catalog JSONL |
| `php artisan catalog:import-images` | Nhập liên kết hình ảnh |
| `php artisan catalog:sync-prices` | Xem trước hoặc đồng bộ giá |
| `php artisan catalog:publish-priced` | Xem trước hoặc xuất bản catalog |
| `php artisan db:seed --class=Database\Seeders\ChatbotKnowledgeSeeder` | Nạp FAQ mẫu |

Chạy lệnh Artisan trong thư mục `api`.

Đọc trợ giúp của lệnh trước khi dùng trên dữ liệu thật:

```powershell
php artisan help catalog:sync-prices
```

### Node.js

Các script của `web` được khai báo trong `web/package.json`.

Các script của `chatbot` được khai báo trong `chatbot/package.json`.

Xem phần kiểm thử để biết các lệnh build, lint và test.

## Kiểm thử và kiểm tra chất lượng

### Laravel

Chạy toàn bộ kiểm thử API:

```powershell
Set-Location D:\TechStore\api
php artisan test
```

Chạy test catalog:

```powershell
php artisan test --filter=CatalogApiTest
```

Chạy test workflow cửa hàng:

```powershell
php artisan test --filter=StoreWorkflowApiTest
```

Suite feature bao phủ xác thực, phân quyền, catalog và quy trình nghiệp vụ.

Các test có thể kiểm tra idempotency checkout và tính toán voucher.

Các test có thể kiểm tra gộp giỏ, địa chỉ và thông báo.

Các test có thể kiểm tra review, import ảnh và ưu đãi học tập.

Đảm bảo Laravel dùng database kiểm thử.

Không chạy test trên database production.

### Giao diện web

Chạy toàn bộ unit/component test:

```powershell
Set-Location D:\TechStore\web
npm test
```

Chạy một tệp test cụ thể:

```powershell
npm test -- --run src/App.test.tsx
```

Chạy lint:

```powershell
npm run lint
```

Chạy build và kiểm tra TypeScript:

```powershell
npm run build
```

Lệnh tổng hợp:

```powershell
npm run check
```

`check` chạy lint, test và build lần lượt.

### Kiểm thử trình duyệt

Cài Chromium khi Playwright chưa có browser:

```powershell
Set-Location D:\TechStore\web
npx playwright install chromium
```

Chạy E2E:

```powershell
npm run test:e2e
```

E2E bao phủ một số luồng cửa hàng đại diện.

Một số response API trong E2E có thể được mô phỏng.

E2E không chứng minh SMTP, ngân hàng hoặc hãng vận chuyển hoạt động.

### Chatbot

Chạy test:

```powershell
Set-Location D:\TechStore\chatbot
npm test
```

Test bao gồm chuẩn hóa văn bản tiếng Việt.

Test bao gồm phân loại intent và trích xuất ngân sách.

Test bao gồm nhận diện nhu cầu gaming và lọc gợi ý.

Test bao gồm FAQ, hội thoại, rate limit và chuyển tiếp hỗ trợ.

### Pint

Chạy kiểm tra định dạng PHP:

```powershell
Set-Location D:\TechStore\api
vendor\bin\pint --test
```

Nếu sửa mã PHP, chạy Pint ở chế độ kiểm tra trước khi hoàn tất.

Không bỏ qua lỗi mới do thay đổi hiện tại gây ra.

## Triển khai

README này không thay thế quy trình triển khai của hạ tầng cụ thể.

Kiểm thử quy trình sao lưu và phục hồi trước khi vận hành dữ liệu thật.

### Cấu hình ứng dụng

- Đặt `APP_ENV=production`.
- Đặt `APP_DEBUG=false`.
- Tạo `APP_KEY` riêng và bảo vệ khóa.
- Dùng URL HTTPS cho `APP_URL`.
- Giới hạn origin CORS đúng các tên miền thực tế.
- Tạo token chatbot ngẫu nhiên, riêng cho môi trường.
- Cấu hình SMTP thật nếu cần gửi email.
- Chỉ cấu hình domain học tập mà cửa hàng chấp nhận.
- Chọn queue và cache phù hợp với hạ tầng.
- Đảm bảo tệp minh chứng nằm trên private storage.

### Database và dữ liệu

- Tạo tài khoản runtime database riêng.
- Tách quyền DDL/migration khỏi tài khoản runtime nếu có thể.
- Chỉ cho phép máy ứng dụng truy cập MySQL.
- Sao lưu trước migration.
- Kiểm tra khả năng phục hồi bản sao lưu định kỳ.
- Theo dõi dung lượng database.
- Rà soát giá và hình ảnh trước khi nhập.
- Không thực hiện thao tác thử nghiệm trên dữ liệu khách hàng thật.

### Mạng và dịch vụ

- Phục vụ web, API và chatbot qua HTTPS.
- Cấu hình trusted proxy đúng với mạng thực tế.
- Không tin forwarded IP header từ nguồn không đáng tin.
- Giữ chatbot trong loopback hoặc mạng riêng.
- Chỉ public reverse proxy cần thiết.
- Chặn truy cập công khai tới MySQL.
- Xác nhận CORS không dùng wildcard không cần thiết.
- Đặt timeout và giới hạn tốc độ ở lớp hạ tầng phù hợp.

### Bí mật, log và tệp

- Đặt `.env` ngoài web root công khai.
- Không commit token, mật khẩu, khóa hoặc thông tin SMTP.
- Không đưa bí mật vào bundle frontend.
- Không log mật khẩu, bearer token, OTP hoặc nội dung giấy tờ định danh.
- Hạn chế quyền đọc log và xoay vòng log định kỳ.
- Xoay vòng bí mật khi nghi ngờ bị lộ.
- Định nghĩa thời gian lưu và quy trình xóa minh chứng.
- Dùng storage bền vững cho tệp cần giữ qua lần triển khai.

### Thanh toán và giao nhận

- Không mô tả QR tĩnh là xác nhận thanh toán tự động.
- Đối soát chuyển khoản với sao kê thực tế.
- Không duyệt đơn chỉ dựa trên xác nhận từ trình duyệt.
- Xác nhận phí vận chuyển và thời gian giao với khách.
- Không quảng bá tracking tự động khi chưa tích hợp carrier.
- Xây dựng quy trình hoàn tiền trước khi nhận thanh toán thật.
- Xác định người chịu trách nhiệm cập nhật trạng thái đơn.

### Bảo mật

Đọc [SECURITY.md](./SECURITY.md) trước khi public dịch vụ.

Checklist trong tài liệu bảo mật không phải chứng nhận bảo mật.

Đánh giá lại rủi ro theo hạ tầng và quy trình vận hành thực tế.

## Xử lý sự cố

### Laravel không kết nối được MySQL

Kiểm tra dịch vụ MySQL đang chạy.

Kiểm tra `DB_HOST`, `DB_PORT`, `DB_DATABASE` và thông tin đăng nhập.

Kiểm tra quyền database của tài khoản ứng dụng.

Kiểm tra PHP đã bật extension `pdo_mysql`.

Đọc thông báo lỗi trong log Laravel.

### API báo thiếu bảng

Chạy `php artisan migrate:status` trong thư mục `api`.

Sao lưu dữ liệu rồi chạy `php artisan migrate`.

Kiểm tra migration có đúng môi trường database không.

Không tạo bảng tạm thủ công để che lỗi migration.

Với bảng `yeu_cau_doi_tra`, kiểm tra migration fulfillment.

Với bảng ưu đãi, kiểm tra migration customer promotions.

Với trường xét duyệt học tập, kiểm tra migration review.

### Trình duyệt không gọi được API

Xác nhận Laravel đang chạy.

Kiểm tra `VITE_API_URL` có đúng tiền tố `/api/v1`.

Kiểm tra origin frontend nằm trong `CORS_ALLOWED_ORIGINS`.

Tránh ghép lặp `/api/v1` trong URL.

Dùng Network panel để xem request và response.

Đọc log Laravel để tìm lỗi phía máy chủ.

### Chatbot không hoạt động

Xác nhận Node.js chatbot đang chạy.

Gọi `GET /health` để kiểm tra tiến trình.

Kiểm tra `LARAVEL_API_URL` trỏ đúng URL gốc API.

Kiểm tra token chatbot giống nhau ở hai dịch vụ.

Kiểm tra origin frontend trong `WEB_ORIGINS`.

Đọc log chatbot và Laravel để xác định lỗi upstream.

### Không nhận được OTP

Kiểm tra email thuộc domain trong `EDUCATION_EMAIL_DOMAINS`.

Kiểm tra `MAIL_MAILER` không còn là `log` nếu cần gửi thật.

Kiểm tra host, port, tài khoản, mật khẩu và địa chỉ gửi SMTP.

Kiểm tra log Laravel hoặc nhật ký nhà cung cấp email.

Xác nhận OTP chưa hết hạn mười phút.

OTP chỉ xác nhận quyền truy cập hộp thư.

### Hồ sơ ưu đãi đang chờ duyệt

Trạng thái chờ duyệt là bình thường sau khi OTP hợp lệ.

Giảm giá chưa được áp dụng cho tới khi quản trị viên duyệt.

Đăng nhập trang `/admin`.

Mở mục **Duyệt ưu đãi HSSV–GV**.

Kiểm tra minh chứng qua endpoint quản trị.

Phê duyệt hoặc từ chối hồ sơ theo quy định.

### Không tải được minh chứng

Xác nhận tài khoản hiện tại có quyền quản trị.

Kiểm tra hồ sơ có đường dẫn tệp minh chứng.

Kiểm tra private storage có tồn tại và đọc được.

Kiểm tra storage bền vững sau triển khai.

Không di chuyển minh chứng vào `public` để né phân quyền.

### Checkout thất bại

Kiểm tra giỏ có sản phẩm còn được phép mua.

Kiểm tra tồn kho hiện tại có thay đổi hay không.

Điền đủ thông tin giao nhận.

Kiểm tra request có UUID `Idempotency-Key`.

Kiểm tra mã giảm giá còn hạn và đủ điều kiện.

Đọc lỗi validation cụ thể từ API.

Khi không rõ request trước đã thành công hay chưa, không tạo khóa mới ngay.

Dùng lại khóa idempotency phù hợp để kiểm tra kết quả đã tạo.

### Ảnh sản phẩm không hiển thị

Kiểm tra tên thư mục ảnh khớp slug URL sản phẩm.

Kiểm tra định dạng và khả năng đọc của tệp ảnh.

Chạy import ảnh và đọc báo cáo.

Kiểm tra thư mục public storage có quyền ghi.

Xác nhận đã chạy `php artisan storage:link`.

Không gắn ảnh của sản phẩm khác để che lỗi import.

### Tồn kho hoặc đơn không xuất hiện

Kiểm tra tài khoản có role và chi nhánh phù hợp.

Kiểm tra trạng thái đơn có thuộc hàng đợi được hiển thị không.

Kiểm tra đơn đã được nhân viên khác nhận hay chưa.

Kiểm tra sản phẩm được phân bổ tồn kho cho chi nhánh nào.

Đọc log audit và lịch sử điều chỉnh nếu có.

## Giới hạn hiện tại

- Không có cổng thanh toán trực tuyến được tích hợp.
- Không xác minh chuyển khoản tự động.
- Không có webhook thanh toán.
- Phí giao hàng và thời gian giao không tự tính.
- Thông tin vận đơn được nhập thủ công.
- Trang quản trị chưa có giao diện tạo chi nhánh.
- Chatbot không tự học từ hội thoại khách hàng.
- Gợi ý chatbot phụ thuộc dữ liệu sản phẩm và thông số catalog.
- Rate limit trong bộ nhớ chatbot không dùng chung nhiều instance.
- Email cục bộ mặc định chỉ ghi log.
- Địa chỉ giao hàng lưu ở trình duyệt không tính phí ship.
- Yêu cầu đổi trả không tự hoàn tiền hoặc tạo nhãn vận chuyển.
- Danh sách yêu thích không đồng bộ qua tài khoản.
- Bản thân README không xác nhận cấu hình vận hành production.

Các giới hạn có thể thay đổi khi mã nguồn được cập nhật.

Hãy xác minh lại code và cấu hình trước khi dựa vào tài liệu này.

## Thuật ngữ

| Thuật ngữ | Giải thích |
| --- | --- |
| API | Giao diện HTTP giữa frontend và dịch vụ nghiệp vụ |
| Bearer token | Token gửi trong header Authorization |
| Checkout | Quy trình xác nhận giỏ và tạo đơn |
| Idempotency key | Khóa giúp xử lý gửi lại request tạo đơn |
| Migration | Tập lệnh thay đổi schema có phiên bản |
| OTP | Mã dùng một lần để xác minh quyền truy cập email |
| Private storage | Vùng lưu tệp không truy cập công khai trực tiếp |
| Soft delete | Đánh dấu xóa để có thể khôi phục |
| JSONL | Tệp chứa một đối tượng JSON trên mỗi dòng |
| FAQ | Câu hỏi thường gặp và câu trả lời |
| HSSV–GV | Học sinh, sinh viên và giáo viên |
| KPI | Chỉ số hiệu suất theo phạm vi vai trò |
| SKU | Mã định danh sản phẩm trong nghiệp vụ |
| CORS | Quy tắc trình duyệt cho phép origin gọi API |
| SMTP | Giao thức gửi email |

## Đóng góp và thay đổi mã nguồn

Đọc luồng nghiệp vụ liên quan trước khi sửa.

Kiểm tra route, middleware, service, frontend và test liên quan.

Giữ validation nghiệp vụ ở API, không chỉ dựa vào giao diện.

Thêm hoặc cập nhật test cho hành vi thay đổi.

Chạy bộ kiểm tra nhỏ nhất bao phủ thay đổi.

Không ghi thông tin cá nhân hoặc bí mật vào fixture không cần thiết.

Không thay đổi dữ liệu thật để tạo test.

Cập nhật tài liệu này khi thay đổi lệnh, route hoặc giới hạn sản phẩm.

## Tài liệu liên quan

- [Tài liệu bảo mật](./SECURITY.md)
- [Route API Laravel](./api/routes/api.php)
- [Migrations](./api/database/migrations)
- [Kiểm thử Laravel](./api/tests/Feature)
- [Mã nguồn giao diện](./web/src)
- [Máy chủ chatbot](./chatbot/src/server.js)
- [Schema lịch sử](./database/techstore.sql)
