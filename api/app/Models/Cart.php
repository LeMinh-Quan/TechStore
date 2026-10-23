<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    protected $table = 'gio_hang';

    protected $primaryKey = 'ma_cart';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'ma_cart',
        'ma_kh',
        'ngay_tao',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class, 'ma_cart', 'ma_cart');
    }
}
