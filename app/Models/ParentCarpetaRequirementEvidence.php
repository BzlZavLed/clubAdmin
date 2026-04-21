<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParentCarpetaRequirementEvidence extends Model
{
    protected $table = 'parent_carpeta_requirement_evidences';

    protected $fillable = [
        'member_id',
        'union_carpeta_requirement_id',
        'submitted_by_user_id',
        'submitted_by_member_id',
        'submitted_via',
        'access_code_id',
        'submitted_ip',
        'submitted_user_agent',
        'evidence_type',
        'text_value',
        'file_path',
        'physical_completed',
        'status',
        'submitted_at',
    ];

    protected $casts = [
        'physical_completed' => 'boolean',
        'submitted_at' => 'datetime',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function requirement()
    {
        return $this->belongsTo(UnionCarpetaRequirement::class, 'union_carpeta_requirement_id');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function submittedByMember()
    {
        return $this->belongsTo(Member::class, 'submitted_by_member_id');
    }

    public function accessCode()
    {
        return $this->belongsTo(PublicMemberEvidenceAccessCode::class, 'access_code_id');
    }
}
