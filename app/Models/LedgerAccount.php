<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LedgerAccount extends Model
{

     use HasFactory;

    protected $fillable = [
        'owner_id', 'owner_type', 'code', 'currency',
    ];

    protected $casts = [
        'owner_id' => 'integer',
    ];
}
