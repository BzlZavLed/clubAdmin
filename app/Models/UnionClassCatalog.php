<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnionClassCatalog extends Model
{
    use HasFactory;

    protected $fillable = [
        'union_club_catalog_id',
        'name',
        'sort_order',
        'status',
    ];

    public function clubCatalog()
    {
        return $this->belongsTo(UnionClubCatalog::class, 'union_club_catalog_id');
    }

    public function clubActivations()
    {
        return $this->hasMany(ClubCarpetaClassActivation::class, 'union_class_catalog_id');
    }
}
