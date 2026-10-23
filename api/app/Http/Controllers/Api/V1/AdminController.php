<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AdminService;
use App\Services\CustomerNotificationService;
use App\Services\OrderStatusService;
use App\Services\VoucherService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function __construct(private readonly AdminService $admins) {}

    public function products(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $scopeError = $this->admins->productScopeError($account);
        if ($scopeError !== null) {
            return response()->json(['message' => $scopeError], 422);
        }
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', 'in:draft,published'],
            'category' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return $this->paginated($this->admins->products($filters, $account));
    }

    public function branches(): JsonResponse
    {
        return response()->json(['data' => $this->admins->branches()]);
    }

    public function storeBranch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:5000'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $id = $this->admins->createBranch($data, $this->adminId($request), $request->ip());

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function trashedProducts(): JsonResponse
    {
        return $this->paginated($this->admins->trashedProducts());
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $data = $request->validate($this->productRules());
        $result = $this->admins->createProduct($data, $this->account($request), $request->ip());
        if (isset($result['status'])) {
            return response()->json(['message' => $result['message']], $result['status']);
        }

        return response()->json(['data' => $result], 201);
    }

    public function updateProduct(Request $request, string $productId): JsonResponse
    {
        $result = $this->admins->updateProduct($productId, $request->validate($this->productRules(true)), $this->account($request), $request->ip());

        return $this->resultResponse($result);
    }

    public function destroyProduct(Request $request, string $productId): JsonResponse
    {
        return $this->resultResponse($this->admins->deleteProduct($productId, $this->account($request), $request->ip()));
    }

    public function restoreProduct(Request $request, string $productId): JsonResponse
    {
        return $this->resultResponse($this->admins->restoreProduct($productId, $this->account($request), $request->ip()));
    }

    public function forceDeleteProduct(Request $request, string $productId): JsonResponse
    {
        return $this->resultResponse($this->admins->forceDeleteProduct($productId, $this->account($request), $request->ip()));
    }

    public function vouchers(): JsonResponse
    {
        return response()->json(['data' => $this->admins->vouchers()]);
    }

    public function storeVoucher(Request $request): JsonResponse
    {
        if (is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper($request->input('code'))]);
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('phieu_giam_gia', $this->admins->voucherCodeColumn())],
            'discount_type' => ['required', 'in:percent,fixed'],
            'discount_value' => ['required', 'integer', 'min:1'],
            'minimum_order_value' => ['sometimes', 'integer', 'min:0'],
            'maximum_discount' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
        ]);
        $code = $this->admins->createVoucher($data, $this->adminId($request), $request->ip());

        return response()->json(['data' => ['code' => $code]], 201);
    }

    public function updateVoucher(Request $request, string $code): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $result = $this->admins->updateVoucher($code, $data['is_active'], $this->adminId($request), $request->ip());

        return $this->resultResponse($result);
    }

    public function stockReceipts(Request $request): JsonResponse
    {
        $result = $this->admins->stockReceipts($this->account($request));
        if (isset($result['status'])) {
            return response()->json(['message' => $result['message']], $result['status']);
        }

        return $this->paginated($result['paginator']);
    }

    public function storeStockReceipt(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier' => ['required', 'string', 'max:200'],
            'reference' => ['nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'string', 'size:10', 'distinct'],
            'items.*.unit_cost' => ['required', 'integer', 'min:0'],
            'items.*.serials' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.serials.*' => ['required', 'string', 'min:3', 'max:100', 'distinct'],
        ]);
        $result = $this->admins->createStockReceipt($data, $this->account($request), $request->ip());
        if (isset($result['status'])) {
            return response()->json(['message' => $result['message']], $result['status']);
        }

        return response()->json(['data' => $result], 201);
    }

    public function warranties(Request $request): JsonResponse
    {
        $result = $this->admins->warranties($this->account($request));
        if (isset($result['status'])) {
            return response()->json(['message' => $result['message']], $result['status']);
        }

        return response()->json($result);
    }

    public function customers(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return $this->paginated($this->admins->customers($filters));
    }

    public function updatePayroll(Request $request, string $employeeId, int $year, int $month): JsonResponse
    {
        $data = $request->validate([
            'base_salary' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'allowance' => ['sometimes', 'numeric', 'min:0', 'max:999999999999'],
            'bonus' => ['sometimes', 'numeric', 'min:0', 'max:999999999999'],
            'deduction' => ['sometimes', 'numeric', 'min:0', 'max:999999999999'],
            'paid' => ['sometimes', 'boolean'],
        ]);

        return $this->resultResponse($this->admins->updatePayroll(
            $data, $employeeId, $year, $month, $this->account($request), $request->ip(),
        ));
    }

    public function orders(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'string', 'max:50'],
            'q' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return $this->paginated($this->admins->orders($filters));
    }

    public function reports(): JsonResponse
    {
        return response()->json(['data' => $this->admins->reports()]);
    }

    public function showOrder(string $orderId): JsonResponse
    {
        $result = $this->admins->showOrder($orderId);
        if ($result === null) {
            return response()->json(['message' => 'Không tìm thấy đơn hàng.'], 404);
        }

        return response()->json(['data' => $result]);
    }

    public function updateOrder(
        Request $request,
        string $orderId,
        OrderStatusService $statuses,
        VoucherService $vouchers,
        CustomerNotificationService $notifications,
    ): JsonResponse {
        $data = $request->validate([
            'action' => ['required', 'in:status,mark_paid,tracking'],
            'status' => ['required_if:action,status', 'in:Cho xac nhan,Dang Giao,Da Giao,huy'],
            'carrier' => ['required_if:action,tracking', 'string', 'max:100'],
            'tracking_code' => ['required_if:action,tracking', 'string', 'max:100'],
            'estimated_delivery' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        $result = $this->admins->updateOrder($data, $orderId, $this->account($request), $request->ip(), $statuses, $vouchers, $notifications);
        if (isset($result['not_found'])) {
            return response()->json(['message' => 'Không tìm thấy đơn hàng.'], 404);
        }

        return response()->json(['message' => $result['message']]);
    }

    private function productRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'category' => [$required, 'string', 'max:100'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'price' => [$required, 'numeric', 'min:0', 'max:9999999999'],
            'list_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'stock' => [$required, 'integer', 'min:0', 'max:1000000'],
            'status' => [$required, 'in:draft,published'],
            'is_used' => ['sometimes', 'boolean'],
            'specifications' => ['sometimes', 'nullable', 'array'],
            'specifications.*' => ['nullable', 'string', 'max:500'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ];
    }

    private function account(Request $request): array
    {
        return $request->attributes->get('api_account');
    }

    private function adminId(Request $request): string
    {
        return $this->account($request)['id'];
    }

    private function resultResponse(array $result): JsonResponse
    {
        if (isset($result['status'])) {
            return response()->json(['message' => $result['message']], $result['status']);
        }

        return response()->json($result);
    }

    private function paginated(LengthAwarePaginator $paginator): JsonResponse
    {
        return response()->json([
            'data' => $paginator->getCollection()->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
