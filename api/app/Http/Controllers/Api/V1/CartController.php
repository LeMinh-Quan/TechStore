<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function show(Request $request, CartService $carts): JsonResponse
    {
        return response()->json([
            'data' => $carts->contentsForCustomer($this->customerId($request)),
        ]);
    }

    public function add(Request $request, CartService $carts): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'string', 'size:10'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        return response()->json([
            'data' => $carts->add(
                $this->customerId($request),
                $data['product_id'],
                $data['quantity'] ?? 1,
            ),
        ], 201);
    }

    public function merge(Request $request, CartService $carts): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'max:20'],
            'items.*.product_id' => ['required', 'string', 'size:10', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        return response()->json([
            'data' => $carts->merge($this->customerId($request), $data['items']),
        ]);
    }

    public function update(Request $request, string $productId, CartService $carts): JsonResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:20']]);
        $cart = $carts->update($this->customerId($request), $productId, $data['quantity']);

        return $cart
            ? response()->json(['data' => $cart])
            : response()->json(['message' => 'Sản phẩm không có trong giỏ hàng.'], 404);
    }

    public function remove(Request $request, string $productId, CartService $carts): JsonResponse
    {
        $cart = $carts->remove($this->customerId($request), $productId);

        return $cart
            ? response()->json(['data' => $cart])
            : response()->json(['message' => 'Sản phẩm không có trong giỏ hàng.'], 404);
    }

    private function customerId(Request $request): string
    {
        return $request->attributes->get('api_account')['id'];
    }
}
