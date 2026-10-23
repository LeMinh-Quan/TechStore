<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\StoreWorkflowService;
use App\Services\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreWorkflowController extends Controller
{
    public function reviews(string $slug, StoreWorkflowService $workflows): JsonResponse
    {
        $reviews = $workflows->reviews($slug);

        return $reviews
            ? response()->json($reviews)
            : response()->json(['message' => 'Không tìm thấy sản phẩm.'], 404);
    }

    public function storeReview(Request $request, string $productId, StoreWorkflowService $workflows): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'content' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        $stored = $workflows->storeReview(
            $request->attributes->get('api_account')['id'],
            $productId,
            $data,
        );

        return $stored
            ? response()->json(['message' => 'Đã lưu đánh giá của bạn.'], 201)
            : response()->json(['message' => 'Không tìm thấy sản phẩm.'], 404);
    }

    public function addresses(Request $request, StoreWorkflowService $workflows): JsonResponse
    {
        return response()->json([
            'data' => $workflows->addresses($request->attributes->get('api_account')['id']),
        ]);
    }

    public function storeAddress(Request $request, StoreWorkflowService $workflows): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'min:1', 'max:20'],
            'recipient_name' => ['required', 'string', 'max:200'],
            'phone' => ['required', 'string', 'regex:/^[0-9+().\-\s]{8,20}$/'],
            'address' => ['required', 'string', 'max:255'],
            'ward' => ['nullable', 'string', 'max:100'],
            'district' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
        ]);
        $workflows->storeAddress($request->attributes->get('api_account')['id'], $data);

        return response()->json(['message' => 'Đã lưu địa chỉ giao hàng.'], 201);
    }

    public function deleteAddress(Request $request, string $label, StoreWorkflowService $workflows): JsonResponse
    {
        $deleted = $workflows->deleteAddress($request->attributes->get('api_account')['id'], $label);

        return $deleted
            ? response()->json(['message' => 'Đã xóa địa chỉ.'])
            : response()->json(['message' => 'Không tìm thấy địa chỉ.'], 404);
    }

    public function validateVoucher(Request $request, VoucherService $vouchers, StoreWorkflowService $workflows): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $result = $workflows->validateVoucher(
            $request->attributes->get('api_account')['id'],
            $data['code'],
            $vouchers,
        );

        return response()->json(['data' => $result]);
    }
}
