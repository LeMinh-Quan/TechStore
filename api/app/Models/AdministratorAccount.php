<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdministratorAccount extends Model
{
    protected $table = 'quan_tri';

    protected $primaryKey = 'tk_admin';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';
}
