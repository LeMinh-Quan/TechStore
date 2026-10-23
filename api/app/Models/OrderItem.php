<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $table = 'chi_tiet_don_hang';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'ma_dh',
        'ma_sp',
        'so_luong',
        'don_gia',
        'ten_sp_snapshot',
        'thong_so_snapshot',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'ma_dh', 'ma_dh');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'ma_sp', 'ma_sp');
    }
}
