<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $table = 'don_hang';

    protected $primaryKey = 'ma_dh';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'ma_dh',
        'idempotency_key',
        'ma_kh',
        'ngay_dat',
        'tong_tien',
        'ma_voucher',
        'tien_giam',
        'loai_uu_dai',
        'trang_thai',
        'ten_nguoi_nhan',
        'dien_thoai_nguoi_nhan',
        'dia_chi_giao_hang',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'ma_dh', 'ma_dh');
    }
}
