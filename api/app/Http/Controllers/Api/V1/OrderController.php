<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CheckoutService;
use App\Services\CustomerNotificationService;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use App\Services\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function checkout(Request $request, CheckoutService $checkout): JsonResponse
    {
        $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'recipient_name' => ['required', 'string', 'max:200'],
            'phone' => ['required', 'string', 'regex:/^[0-9+().\-\s]{8,20}$/'],
            'address' => ['required', 'string', 'max:255'],
            'ward' => ['nullable', 'string', 'max:100'],
            'district' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'payment_method' => ['required', 'in:transfer,momo,cash'],
            'voucher_code' => ['nullable', 'string', 'max:40'],
        ]);
        $result = $checkout->placeOrder(
            $data,
            $request->attributes->get('api_account')['id'],
        );
        if ($result['conflict']) {
            return response()->json(['message' => 'Idempotency key đã được sử dụng.'], 409);
        }

        unset($result['conflict']);

        return response()->json(['data' => $result], $result['replayed'] ? 200 : 201);
    }

    public function index(Request $request, OrderService $orders): JsonResponse
    {
        $accountId = $request->attributes->get('api_account')['id'];

        return response()->json($orders->indexForCustomer($accountId));
    }

    public function show(Request $request, string $orderId, OrderService $orders): JsonResponse
    {
        $accountId = $request->attributes->get('api_account')['id'];
        $order = $orders->findForCustomer($accountId, $orderId);
        if ($order === null) {
            return response()->json(['message' => 'Không tìm thấy đơn hàng.'], 404);
        }

        return response()->json(['data' => $order]);
    }

    public function cancel(Request $request, string $orderId, OrderService $orders, OrderStatusService $statuses, VoucherService $vouchers): JsonResponse
    {
        $accountId = $request->attributes->get('api_account')['id'];
        $cancelled = $orders->cancelForCustomer($accountId, $orderId, $statuses, $vouchers);

        return $cancelled
            ? response()->json(['message' => 'Đã hủy đơn hàng.'])
            : response()->json(['message' => 'Không tìm thấy đơn hàng.'], 404);
    }

    public function warranties(Request $request, OrderService $orders): JsonResponse
    {
        $customerId = $request->attributes->get('api_account')['id'];

        return response()->json(['data' => $orders->warrantiesForCustomer($customerId)]);
    }

    public function notifications(Request $request, CustomerNotificationService $notifications): JsonResponse
    {
        $result = $notifications->listForCustomer($request->attributes->get('api_account')['id']);

        return response()->json([
            'data' => $result->getCollection()->values(),
            'meta' => [
                'current_page' => $result->currentPage(),
                'last_page' => $result->lastPage(),
                'total' => $result->total(),
            ],
        ]);
    }

    public function readNotification(Request $request, int $notificationId, CustomerNotificationService $notifications): JsonResponse
    {
        if (! $notifications->markRead($request->attributes->get('api_account')['id'], $notificationId)) {
            return response()->json(['message' => 'Không tìm thấy thông báo chưa đọc.'], 404);
        }

        return response()->json(['message' => 'Đã đánh dấu thông báo đã đọc.']);
    }

    public function readAllNotifications(Request $request, CustomerNotificationService $notifications): JsonResponse
    {
        return response()->json([
            'data' => ['updated' => $notifications->markAllRead($request->attributes->get('api_account')['id'])],
        ]);
    }
}
