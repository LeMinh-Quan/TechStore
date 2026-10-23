<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $table = 'chi_tiet_gio_hang';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'ma_cart',
        'ma_sp',
        'so_luong',
        'gia',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'ma_cart', 'ma_cart');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'ma_sp', 'ma_sp');
    }
}
