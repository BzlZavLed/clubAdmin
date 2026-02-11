<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskFormSchema extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'schema_json',
    ];

    protected $casts = [
        'schema_json' => 'array',
    ];
}
