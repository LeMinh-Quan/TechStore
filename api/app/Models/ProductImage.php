<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    protected $table = 'san_pham_hinh_anh';

    protected $primaryKey = 'id_hinh_anh';

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'ma_sp', 'ma_sp');
    }
}
