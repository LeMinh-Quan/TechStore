<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CustomerNotificationService;
use App\Services\StaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function __construct(private readonly StaffService $staff) {}

    public function revenue(Request $request): JsonResponse
    {
        $user = $request->attributes->get('api_account')['record'];

        if (in_array($user->role, ['chu_quan', 'chu_doanh_nghiep'], true)) {
            $revenue = $this->staff->companyRevenue();

            return response()->json([
                'scope' => 'toan_doanh_nghiep',
                'total_revenue' => $revenue['total_revenue'],
                'branches' => $revenue['branches'],
            ]);
        }

        if ($user->role === 'giam_doc_chi_nhanh') {
            if (! $user->id_cua_hang) {
                return response()->json(['message' => 'Tài khoản chưa được gán chi nhánh.'], 422);
            }

            return response()->json([
                'scope' => 'chi_nhanh',
                'branch_id' => (int) $user->id_cua_hang,
                'branch_revenue' => $this->staff->branchRevenue($user->id_cua_hang),
            ]);
        }

        if ($user->role === 'nhan_vien') {
            $month = now()->month;
            $year = now()->year;

            return response()->json([
                'scope' => 'ca_nhan',
                'month' => $month,
                'year' => $year,
                'personal_kpi' => $this->staff->employeeRevenue($user->tk_admin, $month, $year),
            ]);
        }

        return response()->json(['message' => 'Không có quyền xem doanh thu.'], 403);
    }

    public function updateStock(Request $request, string $productId): JsonResponse
    {
        $data = $request->validate([
            'stock' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);
        $account = $request->attributes->get('api_account');
        $user = $account['record'];
        if (! $user->id_cua_hang) {
            return response()->json(['message' => 'Tài khoản chưa được gán chi nhánh.'], 422);
        }

        $result = $this->staff->updateStock(
            $productId,
            $user->id_cua_hang,
            $user->tk_admin,
            (int) $data['stock'],
            $request->ip(),
        );

        if (isset($result['not_found'])) {
            return response()->json(['message' => 'Không tìm thấy sản phẩm.'], 404);
        }

        return response()->json(['data' => $result, 'message' => 'Đã cập nhật tồn kho chi nhánh.']);
    }

    public function inventory(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $user = $request->attributes->get('api_account')['record'];
        if (! $user->id_cua_hang) {
            return response()->json(['message' => 'Tài khoản chưa được gán chi nhánh.'], 422);
        }

        $inventory = $this->staff->inventory($user->id_cua_hang, $filters)->withQueryString();

        return response()->json([
            'data' => $inventory->getCollection()->values(),
            'meta' => ['current_page' => $inventory->currentPage(), 'last_page' => $inventory->lastPage(), 'total' => $inventory->total()],
        ]);
    }

    public function inventoryHistory(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'product_id' => ['sometimes', 'string', 'max:20'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $user = $request->attributes->get('api_account')['record'];
        if (! $user->id_cua_hang) {
            return response()->json(['message' => 'Tài khoản chưa được gán chi nhánh.'], 422);
        }

        $history = $this->staff->inventoryHistory($user->id_cua_hang, $filters)->withQueryString();

        return response()->json([
            'data' => $history->getCollection()->values(),
            'meta' => ['current_page' => $history->currentPage(), 'last_page' => $history->lastPage(), 'total' => $history->total()],
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'in:Cho xac nhan,Dang Giao'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $account = $request->attributes->get('api_account');
        $user = $account['record'];
        if (! $user->id_cua_hang) {
            return response()->json(['message' => 'Tài khoản chưa được gán chi nhánh.'], 422);
        }

        $orders = $this->staff->orders(
            $user->id_cua_hang,
            $user->role === 'nhan_vien',
            $user->tk_admin,
            $filters,
        )->withQueryString();

        return response()->json([
            'data' => $orders->getCollection()->values(),
            'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'total' => $orders->total()],
        ]);
    }

    public function claimOrder(Request $request, string $orderId): JsonResponse
    {
        $user = $request->attributes->get('api_account')['record'];
        if (! $user->id_cua_hang) {
            return response()->json(['message' => 'Tài khoản chưa được gán chi nhánh.'], 422);
        }

        $result = $this->staff->claimOrder($orderId, $user->id_cua_hang, $user->tk_admin, $request->ip());

        if (isset($result['not_found'])) {
            return response()->json(['message' => 'Không tìm thấy đơn hàng.'], 404);
        }
        if (isset($result['conflict'])) {
            return response()->json(['message' => 'Đơn hàng đã được gán cho nhân viên hoặc chi nhánh khác.'], 409);
        }

        return response()->json(['message' => 'Đã nhận xử lý đơn hàng.']);
    }

    public function payroll(Request $request): JsonResponse
    {
        $account = $request->attributes->get('api_account');

        return response()->json(['data' => $this->staff->payroll($account['id'])]);
    }

    public function updateWarranty(Request $request, string $serialNumber, CustomerNotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,in_repair,completed,rejected'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $account = $request->attributes->get('api_account');
        $record = $account['record'];
        $branchManager = ($record->role ?? null) === 'giam_doc_chi_nhanh';
        if ($branchManager && ! $record->id_cua_hang) {
            return response()->json(['message' => 'Không thể xác định phạm vi chi nhánh cho bảo hành.'], 503);
        }

        $result = $this->staff->updateWarranty(
            $serialNumber,
            $data,
            $account['id'],
            $request->ip(),
            $branchManager,
            $record->id_cua_hang ?? null,
            $notifications,
        );

        if (isset($result['scope_unavailable'])) {
            return response()->json(['message' => 'Không thể xác định phạm vi chi nhánh cho bảo hành.'], 503);
        }
        if (isset($result['not_found'])) {
            return response()->json(['message' => 'Không tìm thấy bảo hành thuộc chi nhánh của bạn.'], 404);
        }

        return response()->json(['message' => 'Đã cập nhật trạng thái xử lý bảo hành.']);
    }
}
