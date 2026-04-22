<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssociationWorkplanPublication extends Model
{
    protected $fillable = [
        'association_id',
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

    public function association()
    {
        return $this->belongsTo(Association::class);
    }
}
