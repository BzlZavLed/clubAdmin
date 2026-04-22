<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnionClubCatalog extends Model
{
    use HasFactory;

    protected $fillable = [
        'union_id',
        'name',
        'club_type',
        'sort_order',
        'status',
    ];

    public function union()
    {
        return $this->belongsTo(Union::class);
    }

    public function classCatalogs()
    {
        return $this->hasMany(UnionClassCatalog::class)->orderBy('sort_order')->orderBy('id');
    }
}
