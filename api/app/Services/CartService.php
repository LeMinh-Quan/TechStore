<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CartService
{
    public function contentsForCustomer(string $customerId): array
    {
        $cart = $this->findOrCreateCart($customerId);

        return $this->contents($cart->ma_cart);
    }

    public function add(string $customerId, string $productId, int $quantity): array
    {
        $cart = $this->findOrCreateCart($customerId);

        DB::transaction(function () use ($cart, $productId, $quantity): void {
            $product = Product::query()->where('ma_sp', $productId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if (! $product || $product->trang_thai_hien_thi !== 'published' || (int) $product->gia_ban <= 0) {
                throw ValidationException::withMessages(['product_id' => ['Sản phẩm hiện không được bán.']]);
            }

            $existing = DB::table('chi_tiet_gio_hang')
                ->where('ma_cart', $cart->ma_cart)
                ->where('ma_sp', $product->ma_sp)
                ->lockForUpdate()
                ->first();
            $requestedTotal = $quantity + (int) ($existing->so_luong ?? 0);
            if ($requestedTotal > (int) $product->so_luong) {
                throw ValidationException::withMessages(['quantity' => ['Số lượng tồn kho không đủ.']]);
            }

            DB::table('chi_tiet_gio_hang')->updateOrInsert(
                ['ma_cart' => $cart->ma_cart, 'ma_sp' => $product->ma_sp],
                ['so_luong' => $requestedTotal, 'gia' => $product->gia_ban],
            );
        });

        return $this->contents($cart->ma_cart);
    }

    public function merge(string $customerId, array $items): array
    {
        $cart = $this->findOrCreateCart($customerId);

        DB::transaction(function () use ($cart, $items): void {
            foreach ($items as $item) {
                $product = Product::query()->where('ma_sp', $item['product_id'])
                    ->whereNull('deleted_at')
                    ->where('trang_thai_hien_thi', 'published')
                    ->lockForUpdate()
                    ->first();
                if (! $product || (int) $product->gia_ban <= 0) {
                    throw ValidationException::withMessages(['items' => ['Một sản phẩm trong giỏ không còn được bán.']]);
                }

                $existing = DB::table('chi_tiet_gio_hang')
                    ->where('ma_cart', $cart->ma_cart)
                    ->where('ma_sp', $product->ma_sp)
                    ->lockForUpdate()
                    ->first();
                $quantity = (int) $item['quantity'] + (int) ($existing->so_luong ?? 0);
                if ($quantity > (int) $product->so_luong) {
                    throw ValidationException::withMessages(['items' => ['Số lượng giỏ hàng vượt quá tồn kho.']]);
                }

                DB::table('chi_tiet_gio_hang')->updateOrInsert(
                    ['ma_cart' => $cart->ma_cart, 'ma_sp' => $product->ma_sp],
                    ['so_luong' => $quantity, 'gia' => $product->gia_ban],
                );
            }
        });

        return $this->contents($cart->ma_cart);
    }

    public function update(string $customerId, string $productId, int $quantity): ?array
    {
        $cart = $this->findOrCreateCart($customerId);
        $product = Product::query()->where('ma_sp', $productId)
            ->whereNull('deleted_at')
            ->where('trang_thai_hien_thi', 'published')
            ->first();
        if (! $product || $quantity > (int) $product->so_luong) {
            throw ValidationException::withMessages(['quantity' => ['Số lượng tồn kho không đủ hoặc sản phẩm không tồn tại.']]);
        }

        $updated = DB::table('chi_tiet_gio_hang')
            ->where('ma_cart', $cart->ma_cart)
            ->where('ma_sp', $productId)
            ->update(['so_luong' => $quantity, 'gia' => $product->gia_ban]);

        return $updated ? $this->contents($cart->ma_cart) : null;
    }

    public function remove(string $customerId, string $productId): ?array
    {
        $cart = $this->findOrCreateCart($customerId);
        $deleted = DB::table('chi_tiet_gio_hang')
            ->where('ma_cart', $cart->ma_cart)
            ->where('ma_sp', $productId)
            ->delete();

        return $deleted ? $this->contents($cart->ma_cart) : null;
    }

    private function findOrCreateCart(string $customerId): Cart
    {
        $cart = Cart::query()
            ->where('ma_kh', $customerId)
            ->orderByDesc('ngay_tao')
            ->first();
        if ($cart) {
            return $cart;
        }

        return Cart::query()->create([
            'ma_cart' => Str::upper(Str::random(20)),
            'ma_kh' => $customerId,
            'ngay_tao' => now()->toDateString(),
        ]);
    }

    private function contents(string $cartId): array
    {
        $items = DB::table('chi_tiet_gio_hang')
            ->join('san_pham', 'san_pham.ma_sp', '=', 'chi_tiet_gio_hang.ma_sp')
            ->where('chi_tiet_gio_hang.ma_cart', $cartId)
            ->whereNull('san_pham.deleted_at')
            ->select([
                'san_pham.ma_sp as product_id',
                'san_pham.slug',
                'san_pham.ten_sp as name',
                'san_pham.gia_ban as price',
                'san_pham.so_luong as stock',
                'chi_tiet_gio_hang.so_luong as quantity',
            ])
            ->orderBy('san_pham.ten_sp')
            ->get()
            ->map(fn ($item) => [
                'product_id' => $item->product_id,
                'slug' => $item->slug,
                'name' => $item->name,
                'price' => (float) $item->price,
                'stock' => (int) $item->stock,
                'quantity' => (int) $item->quantity,
                'line_total' => (float) $item->price * (int) $item->quantity,
            ])
            ->all();

        return [
            'items' => $items,
            'total' => array_sum(array_column($items, 'line_total')),
        ];
    }
}
