<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubTypeCatalog extends Model
{
    protected $fillable = [
        'code',
        'name',
        'sort_order',
        'status',
    ];
}
