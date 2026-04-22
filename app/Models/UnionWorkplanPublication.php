<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnionWorkplanPublication extends Model
{
    protected $fillable = [
        'union_id',
        'year',
        'status',
        'published_at',
        'unpublished_at',
        'published_by',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'unpublished_at' => 'datetime',
    ];

    public function union()
    {
        return $this->belongsTo(Union::class);
    }
}
