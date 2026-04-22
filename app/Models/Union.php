<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Union extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'evaluation_system',
        'status',
    ];

    public function associations()
    {
        return $this->hasMany(Association::class);
    }

    public function carpetaYears()
    {
        return $this->hasMany(UnionCarpetaYear::class)->orderByDesc('year')->orderByDesc('id');
    }

    public function clubCatalogs()
    {
        return $this->hasMany(UnionClubCatalog::class)->orderBy('sort_order')->orderBy('id');
    }

    public function workplanPublications()
    {
        return $this->hasMany(UnionWorkplanPublication::class);
    }
}
