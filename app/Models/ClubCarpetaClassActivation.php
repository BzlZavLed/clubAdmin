<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClubCarpetaClassActivation extends Model
{
    use HasFactory;

    protected $fillable = [
        'club_id',
        'union_class_catalog_id',
        'assigned_staff_id',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function unionClassCatalog()
    {
        return $this->belongsTo(UnionClassCatalog::class, 'union_class_catalog_id');
    }

    public function assignedStaff()
    {
        return $this->belongsTo(Staff::class, 'assigned_staff_id');
    }
}
