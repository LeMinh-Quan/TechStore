<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAccount extends Model
{
    protected $table = 'nguoi_dung';

    protected $primaryKey = 'ma_kh';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'ma_kh',
        'ten_khach_hang',
        'ten_dang_nhap',
        'email',
        'so_dien_thoai',
        'mat_khau',
    ];
}
