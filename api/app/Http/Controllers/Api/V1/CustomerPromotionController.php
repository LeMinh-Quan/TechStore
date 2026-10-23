<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\EducationDiscountVerification;
use App\Services\CustomerNotificationService;
use App\Services\EducationDiscountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class CustomerPromotionController extends Controller
{
    public function vouchers(Request $request): JsonResponse
    {
        $customerId = $request->attributes->get('api_account')['id'];
        $legacy = DB::getSchemaBuilder()->hasColumn('phieu_giam_gia', 'ma_code');
        $codeColumn = $legacy ? 'ma_code' : 'code';
        $activeColumn = $legacy ? 'trang_thai' : 'is_active';
        $startColumn = $legacy ? 'ngay_bat_dau' : 'starts_at';
        $endColumn = $legacy ? 'ngay_ket_thuc' : 'expires_at';
        $limitColumn = $legacy ? 'so_luong' : 'usage_limit';
        $countColumn = $legacy ? 'da_dung' : 'usage_count';
        $claimed = DB::table('voucher_da_thu_thap')
            ->where('ma_kh', $customerId)
            ->pluck('code')
            ->all();
        $vouchers = DB::table('phieu_giam_gia')
            ->where($activeColumn, true)
            ->where(function ($query) use ($startColumn): void {
                $query->whereNull($startColumn)->orWhere($startColumn, '<=', now());
            })
            ->where(function ($query) use ($endColumn): void {
                $query->whereNull($endColumn)->orWhere($endColumn, '>=', now());
            })
            ->where(function ($query) use ($countColumn, $limitColumn): void {
                $query->whereNull($limitColumn)->orWhereColumn($countColumn, '<', $limitColumn);
            })
            ->orderBy($codeColumn)
            ->get()
            ->map(function (object $voucher) use ($legacy, $codeColumn, $claimed): array {
                $code = $voucher->{$codeColumn};

                return [
                    'code' => $code,
                    'discount_type' => $legacy
                        ? ($voucher->loai_giam === 'percentage' ? 'percent' : 'fixed')
                        : $voucher->discount_type,
                    'discount_value' => (int) ($legacy ? $voucher->gia_tri_giam : $voucher->discount_value),
                    'minimum_order_value' => (int) ($legacy ? $voucher->gia_tri_don_toi_thieu : $voucher->minimum_order_value),
                    'maximum_discount' => $legacy ? $voucher->giam_toi_da : $voucher->maximum_discount,
                    'expires_at' => $legacy ? $voucher->ngay_ket_thuc : $voucher->expires_at,
                    'claimed' => in_array($code, $claimed, true),
                ];
            })
            ->values();

        return response()->json(['data' => $vouchers]);
    }

    public function claimVoucher(Request $request, string $code): JsonResponse
    {
        $customerId = $request->attributes->get('api_account')['id'];
        $legacy = DB::getSchemaBuilder()->hasColumn('phieu_giam_gia', 'ma_code');
        $codeColumn = $legacy ? 'ma_code' : 'code';
        $voucher = DB::table('phieu_giam_gia')->where($codeColumn, strtoupper($code))->first();
        if (! $voucher) {
            return response()->json(['message' => 'Không tìm thấy mã giảm giá.'], 404);
        }
        if (! $this->voucherAvailable($voucher, $legacy)) {
            throw ValidationException::withMessages(['code' => ['Mã giảm giá hiện không còn khả dụng.']]);
        }

        DB::table('voucher_da_thu_thap')->insertOrIgnore([
            'ma_kh' => $customerId,
            'code' => strtoupper($code),
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Đã thu thập mã giảm giá.']);
    }

    public function educationStatus(Request $request, EducationDiscountService $education): JsonResponse
    {
        $customerId = $request->attributes->get('api_account')['id'];
        $application = DB::table('uu_dai_hoc_tap')->where('ma_kh', $customerId)->first();
        $level = $application ? (EducationDiscountService::levels()[$application->doi_tuong] ?? null) : null;

        return response()->json([
            'data' => $application ? [
                'status' => $application->trang_thai,
                'verified' => $application->trang_thai === 'approved',
                'email' => $application->email_truong,
                'audience' => $level['label'] ?? $application->doi_tuong,
                'discount_percent' => $application->trang_thai === 'approved'
                    ? $education->percentForCustomer($customerId)
                    : null,
                'review_note' => $application->ghi_chu_duyet,
            ] : ['status' => 'not_submitted', 'verified' => false],
        ]);
    }

    public function requestEducationCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'audience' => ['required', Rule::in(array_keys(EducationDiscountService::levels()))],
        ]);
        $customerId = $request->attributes->get('api_account')['id'];
        $existingApplication = DB::table('uu_dai_hoc_tap')->where('ma_kh', $customerId)->first();
        if ($existingApplication && $existingApplication->trang_thai !== 'rejected') {
            throw ValidationException::withMessages([
                'email' => [$existingApplication->trang_thai === 'approved'
                    ? 'Ưu đãi của bạn đã được duyệt.'
                    : 'Hồ sơ ưu đãi đang chờ nhân viên xem xét.'],
            ]);
        }

        $email = strtolower(trim($data['email']));
        $domain = substr(strrchr($email, '@') ?: '', 1);
        $allowedDomains = config('services.education_discount.email_domains', []);
        if (! in_array($domain, $allowedDomains, true)) {
            throw ValidationException::withMessages([
                'email' => ['Email phải thuộc tên miền trường học đã được cửa hàng cấu hình.'],
            ]);
        }

        $code = (string) random_int(100000, 999999);
        DB::table('xac_minh_hoc_tap')->updateOrInsert(
            ['ma_kh' => $customerId],
            [
                'email_truong' => $email,
                'doi_tuong' => $data['audience'],
                'ma_otp_hash' => Hash::make($code),
                'so_lan_thu' => 0,
                'expires_at' => now()->addMinutes(10),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        Mail::to($email)->send(new EducationDiscountVerification(
            $code,
            EducationDiscountService::levels()[$data['audience']]['label'],
        ));

        return response()->json(['message' => 'Đã gửi mã xác minh đến email trường của bạn.']);
    }

    public function verifyEducationCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'digits:6'],
            'evidence' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);
        $customerId = $request->attributes->get('api_account')['id'];
        $challenge = DB::table('xac_minh_hoc_tap')->where('ma_kh', $customerId)->first();
        if (! $challenge || now()->greaterThan($challenge->expires_at) || $challenge->so_lan_thu >= 5) {
            DB::table('xac_minh_hoc_tap')->where('ma_kh', $customerId)->delete();
            throw ValidationException::withMessages(['code' => ['Mã xác minh đã hết hạn hoặc không còn hiệu lực.']]);
        }
        if (! Hash::check($data['code'], $challenge->ma_otp_hash)) {
            DB::table('xac_minh_hoc_tap')->where('ma_kh', $customerId)->increment('so_lan_thu');
            throw ValidationException::withMessages(['code' => ['Mã xác minh không chính xác.']]);
        }

        $evidencePath = $data['evidence']->store("education-discounts/{$customerId}", 'local');
        if ($evidencePath === false) {
            throw new \RuntimeException('Không thể lưu minh chứng ưu đãi học tập.');
        }

        $existingApplication = DB::table('uu_dai_hoc_tap')->where('ma_kh', $customerId)->first();
        try {
            DB::transaction(function () use ($customerId, $challenge, $evidencePath): void {
                DB::table('uu_dai_hoc_tap')->updateOrInsert(
                    ['ma_kh' => $customerId],
                    [
                        'email_truong' => $challenge->email_truong,
                        'doi_tuong' => $challenge->doi_tuong,
                        'verified_at' => now(),
                        'trang_thai' => 'pending',
                        'minh_chung_path' => $evidencePath,
                        'ghi_chu_duyet' => null,
                        'nguoi_duyet' => null,
                        'duyet_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
                DB::table('xac_minh_hoc_tap')->where('ma_kh', $customerId)->delete();
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($evidencePath);
            throw $error;
        }
        if ($existingApplication?->minh_chung_path) {
            Storage::disk('local')->delete($existingApplication->minh_chung_path);
        }

        return response()->json(['message' => 'Email đã xác minh; hồ sơ và minh chứng đang chờ nhân viên duyệt.']);
    }

    public function adminEducationRequests(): JsonResponse
    {
        $requests = DB::table('uu_dai_hoc_tap')
            ->select([
                'ma_kh',
                'email_truong',
                'doi_tuong',
                'trang_thai',
                'verified_at',
                'created_at',
                'ghi_chu_duyet',
                'minh_chung_path',
            ])
            ->orderByRaw("CASE WHEN trang_thai = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(function (object $application): array {
                $level = EducationDiscountService::levels()[$application->doi_tuong] ?? null;

                return [
                    'customer_id' => $application->ma_kh,
                    'email' => $application->email_truong,
                    'audience' => $level['label'] ?? $application->doi_tuong,
                    'discount_percent' => $level['discount'] ?? null,
                    'status' => $application->trang_thai,
                    'verified_at' => $application->verified_at,
                    'created_at' => $application->created_at,
                    'review_note' => $application->ghi_chu_duyet,
                    'has_evidence' => $application->minh_chung_path !== null,
                ];
            });

        return response()->json(['data' => $requests]);
    }

    public function adminEducationEvidence(string $customerId)
    {
        $application = DB::table('uu_dai_hoc_tap')
            ->where('ma_kh', $customerId)
            ->first(['minh_chung_path']);
        if (! $application?->minh_chung_path || ! Storage::disk('local')->exists($application->minh_chung_path)) {
            return response()->json(['message' => 'Không tìm thấy minh chứng.'], 404);
        }

        return Storage::disk('local')->download(
            $application->minh_chung_path,
            "education-proof-{$customerId}.".pathinfo($application->minh_chung_path, PATHINFO_EXTENSION),
        );
    }

    public function reviewEducationRequest(Request $request, string $customerId): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['required_if:status,rejected', 'nullable', 'string', 'max:2000'],
        ]);
        $application = DB::transaction(function () use ($customerId, $data, $request): ?object {
            $application = DB::table('uu_dai_hoc_tap')->where('ma_kh', $customerId)->lockForUpdate()->first();
            if (! $application) {
                return null;
            }
            if ($application->trang_thai !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => ['Chỉ hồ sơ đang chờ mới có thể được duyệt hoặc từ chối.'],
                ]);
            }
            if ($data['status'] === 'approved'
                && (! $application->minh_chung_path || ! Storage::disk('local')->exists($application->minh_chung_path))) {
                throw ValidationException::withMessages([
                    'status' => ['Không thể duyệt hồ sơ khi chưa có minh chứng hợp lệ.'],
                ]);
            }

            DB::table('uu_dai_hoc_tap')->where('ma_kh', $customerId)->update([
                'trang_thai' => $data['status'],
                'ghi_chu_duyet' => $data['note'] ?? null,
                'nguoi_duyet' => $request->attributes->get('api_account')['id'],
                'duyet_at' => now(),
                'updated_at' => now(),
            ]);

            return $application;
        });
        if (! $application) {
            return response()->json(['message' => 'Không tìm thấy hồ sơ ưu đãi.'], 404);
        }

        $approved = $data['status'] === 'approved';
        app(CustomerNotificationService::class)->create(
            $customerId,
            'education_discount.updated',
            'Cập nhật hồ sơ ưu đãi học tập',
            $approved
                ? "Hồ sơ ưu đãi {$application->doi_tuong} đã được duyệt."
                : 'Hồ sơ ưu đãi học tập chưa được duyệt.'.(! empty($data['note']) ? " Lý do: {$data['note']}" : ''),
        );

        return response()->json(['message' => $approved ? 'Đã duyệt hồ sơ ưu đãi.' : 'Đã từ chối hồ sơ ưu đãi.']);
    }

    private function voucherAvailable(object $voucher, bool $legacy): bool
    {
        $active = $legacy ? $voucher->trang_thai : $voucher->is_active;
        $startsAt = $legacy ? $voucher->ngay_bat_dau : $voucher->starts_at;
        $expiresAt = $legacy ? $voucher->ngay_ket_thuc : $voucher->expires_at;
        $usageLimit = $legacy ? $voucher->so_luong : $voucher->usage_limit;
        $usageCount = $legacy ? $voucher->da_dung : $voucher->usage_count;

        return (bool) $active
            && (! $startsAt || now()->greaterThanOrEqualTo($startsAt))
            && (! $expiresAt || now()->lessThanOrEqualTo($expiresAt))
            && ($usageLimit === null || $usageCount < $usageLimit);
    }
}
