<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CustomerNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReturnRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customerId = $request->attributes->get('api_account')['id'];

        return response()->json([
            'data' => DB::table('yeu_cau_doi_tra')
                ->where('ma_kh', $customerId)
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function store(Request $request, string $orderId): JsonResponse
    {
        $customerId = $request->attributes->get('api_account')['id'];
        $data = $request->validate([
            'type' => ['required', Rule::in(['return', 'exchange'])],
            'reason' => ['required', Rule::in(['damaged', 'wrong_item', 'defective', 'changed_mind', 'other'])],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $requestId = DB::transaction(function () use ($data, $customerId, $orderId): int {
            $order = DB::table('don_hang')->where('ma_dh', $orderId)
                ->where('ma_kh', $customerId)->lockForUpdate()->first();
            if (! $order || $order->trang_thai !== 'Da Giao') {
                throw ValidationException::withMessages(['order' => ['Chỉ có thể gửi yêu cầu cho đơn đã giao của bạn.']]);
            }
            $deliveredAt = DB::table('nhat_ky_trang_thai_don')
                ->where('ma_dh', $orderId)
                ->where('trang_thai', 'Da Giao')
                ->orderByDesc('thoi_gian_tao')
                ->value('thoi_gian_tao');
            if (! $deliveredAt || \Illuminate\Support\Carbon::parse($deliveredAt)->lt(now()->subDays(7))) {
                throw ValidationException::withMessages(['order' => ['Thời hạn gửi yêu cầu đổi/trả là 7 ngày kể từ khi đơn được giao.']]);
            }
            if (DB::table('yeu_cau_doi_tra')->where('ma_dh', $orderId)->exists()) {
                throw ValidationException::withMessages(['order' => ['Đơn hàng này đã có yêu cầu đổi/trả.']]);
            }

            $id = DB::table('yeu_cau_doi_tra')->insertGetId([
                'ma_dh' => $orderId,
                'ma_kh' => $customerId,
                'loai_yeu_cau' => $data['type'],
                'ly_do' => $data['reason'],
                'mo_ta' => $data['description'] ?? null,
                'trang_thai' => 'Cho xu ly',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            app(CustomerNotificationService::class)->create(
                $customerId,
                'return.created',
                'Đã nhận yêu cầu đổi/trả',
                "Cửa hàng đã nhận yêu cầu đổi/trả cho đơn {$orderId}.",
                $orderId,
            );

            return $id;
        });

        return response()->json(['data' => ['id' => $requestId]], 201);
    }

    public function adminIndex(): JsonResponse
    {
        return response()->json([
            'data' => DB::table('yeu_cau_doi_tra as r')
                ->join('don_hang as o', 'o.ma_dh', '=', 'r.ma_dh')
                ->leftJoin('nguoi_dung as u', 'u.ma_kh', '=', 'r.ma_kh')
                ->select(['r.*', 'u.email as customer_email', 'o.tong_tien'])
                ->orderByRaw("CASE WHEN r.trang_thai = 'Cho xu ly' THEN 0 ELSE 1 END")
                ->orderByDesc('r.created_at')
                ->limit(200)
                ->get(),
        ]);
    }

    public function adminUpdate(Request $request, int $requestId): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['Approved', 'Rejected', 'Completed'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $transitions = [
            'Cho xu ly' => ['Approved', 'Rejected'],
            'Approved' => ['Completed'],
            'Rejected' => [],
            'Completed' => [],
        ];
        $statusLabels = ['Approved' => 'Đã chấp thuận', 'Rejected' => 'Đã từ chối', 'Completed' => 'Đã hoàn tất', 'Cho xu ly' => 'Đang chờ xử lý'];
        $updated = DB::transaction(function () use ($data, $requestId, $request, $statusLabels, $transitions): bool {
            $return = DB::table('yeu_cau_doi_tra')->where('id', $requestId)->lockForUpdate()->first();
            if (! $return) {
                return false;
            }
            if ($return->trang_thai !== $data['status']
                && ! in_array($data['status'], $transitions[$return->trang_thai] ?? [], true)) {
                throw ValidationException::withMessages(['status' => ['Trạng thái yêu cầu đổi/trả không thể chuyển theo quy trình hiện tại.']]);
            }
            DB::table('yeu_cau_doi_tra')->where('id', $requestId)->update([
                'trang_thai' => $data['status'],
                'ghi_chu_nhan_vien' => $data['note'] ?? null,
                'nguoi_xu_ly' => $request->attributes->get('api_account')['id'],
                'updated_at' => now(),
            ]);
            if ($return->trang_thai !== $data['status']) {
                app(CustomerNotificationService::class)->create(
                    $return->ma_kh,
                    'return.updated',
                    'Cập nhật yêu cầu đổi/trả',
                    "Yêu cầu đổi/trả cho đơn {$return->ma_dh}: {$statusLabels[$data['status']]}."
                        .(! empty($data['note']) ? " Ghi chú: {$data['note']}" : ''),
                    $return->ma_dh,
                );
            }

            return true;
        });
        if (! $updated) {
            return response()->json(['message' => 'Không tìm thấy yêu cầu đổi/trả.'], 404);
        }

        return response()->json(['message' => 'Đã cập nhật yêu cầu đổi/trả.']);
    }
}
