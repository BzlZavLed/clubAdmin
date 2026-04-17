<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseStatusCatalog extends Model
{
    protected $fillable = [
        'status',
        'name',
        'description',
    ];
}
