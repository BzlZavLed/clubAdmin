<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestitureRequirementReview extends Model
{
    protected $fillable = [
        'investiture_request_member_id',
        'union_carpeta_requirement_id',
        'parent_carpeta_requirement_evidence_id',
        'status',
        'notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function requestMember()
    {
        return $this->belongsTo(InvestitureRequestMember::class, 'investiture_request_member_id');
    }

    public function requirement()
    {
        return $this->belongsTo(UnionCarpetaRequirement::class, 'union_carpeta_requirement_id');
    }

    public function evidence()
    {
        return $this->belongsTo(ParentCarpetaRequirementEvidence::class, 'parent_carpeta_requirement_evidence_id');
    }
}
