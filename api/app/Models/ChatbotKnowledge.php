<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatbotKnowledge extends Model
{
    protected $table = 'chatbot_huan_luyen';

    public $timestamps = false;

    protected $fillable = [
        'y_dinh',
        'cau_hoi',
        'phan_hoi',
        'trang_thai',
        'thoi_gian_tao',
    ];
}
