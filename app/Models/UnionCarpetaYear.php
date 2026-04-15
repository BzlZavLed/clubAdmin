<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnionCarpetaYear extends Model
{
    use HasFactory;

    protected $fillable = [
        'union_id',
        'year',
        'status',
        'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function union()
    {
        return $this->belongsTo(Union::class);
    }

    public function requirements()
    {
        return $this->hasMany(UnionCarpetaRequirement::class)->orderBy('sort_order')->orderBy('id');
    }
}
