<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $table = 'san_pham';

    protected $primaryKey = 'ma_sp';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $casts = [
        'thong_so_ky_thuat' => 'array',
    ];

    public function scopeAvailableForSale(Builder $query): Builder
    {
        return $query
            ->where('trang_thai_hien_thi', 'published')
            ->where('gia_ban', '>', 0)
            ->where('so_luong', '>', 0);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'ma_sp', 'ma_sp')
            ->orderBy('thu_tu');
    }
}
