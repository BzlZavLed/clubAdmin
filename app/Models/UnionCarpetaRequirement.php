<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnionCarpetaRequirement extends Model
{
    use HasFactory;

    protected $fillable = [
        'union_carpeta_year_id',
        'title',
        'description',
        'club_type',
        'class_name',
        'requirement_type',
        'validation_mode',
        'allowed_evidence_types',
        'evidence_instructions',
        'sort_order',
        'status',
    ];

    protected $casts = [
        'allowed_evidence_types' => 'array',
    ];

    public function carpetaYear()
    {
        return $this->belongsTo(UnionCarpetaYear::class, 'union_carpeta_year_id');
    }
}
