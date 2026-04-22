<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestitureRequest extends Model
{
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_AUTHORIZED = 'authorized';
    public const STATUS_DATE_CHANGE_REQUESTED = 'date_change_requested';
    public const STATUS_RETURNED = 'returned';

    protected $fillable = [
        'union_id',
        'association_id',
        'district_id',
        'club_id',
        'union_carpeta_year_id',
        'carpeta_year',
        'club_type',
        'status',
        'director_notes',
        'tentative_investiture_date',
        'approved_investiture_date',
        'evaluator_notes',
        'requested_by',
        'submitted_at',
        'assigned_evaluator_type',
        'assigned_evaluator_id',
        'assigned_evaluator_name',
        'assigned_evaluator_email',
        'assigned_at',
        'assigned_by',
        'completed_by',
        'completed_at',
        'authorized_by',
        'authorized_at',
        'authorization_person_name',
        'ceremony_representative_name',
        'ceremony_representative_email',
        'ceremony_representative_phone',
        'date_change_reason',
        'date_change_requested_at',
        'date_change_requested_by',
    ];

    protected $casts = [
        'tentative_investiture_date' => 'date',
        'approved_investiture_date' => 'date',
        'submitted_at' => 'datetime',
        'assigned_at' => 'datetime',
        'completed_at' => 'datetime',
        'authorized_at' => 'datetime',
        'date_change_requested_at' => 'datetime',
    ];

    public function union()
    {
        return $this->belongsTo(Union::class);
    }

    public function association()
    {
        return $this->belongsTo(Association::class);
    }

    public function district()
    {
        return $this->belongsTo(District::class);
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function carpetaYear()
    {
        return $this->belongsTo(UnionCarpetaYear::class, 'union_carpeta_year_id');
    }

    public function members()
    {
        return $this->hasMany(InvestitureRequestMember::class);
    }
}
