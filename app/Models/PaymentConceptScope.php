<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentConceptScope extends Model
{
    use SoftDeletes;
    
    protected $fillable = [
        'payment_concept_id',
        'scope_type',
        'club_id',
        'class_id',
        'member_id',
        'staff_id',
        'staff_all',        // <-- NEW

    ];
    protected $casts = [
        'club_id'     => 'integer',
        'class_id'    => 'integer',
        'member_id'   => 'integer',
        'staff_id'    => 'integer',
        'staff_all'   => 'boolean',   // <-- NEW
    ];
    public function concept()
    {
        return $this->belongsTo(PaymentConcept::class, 'payment_concept_id');
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function class()
    {
        return $this->belongsTo(ClubClass::class, 'class_id');
    }

    public function member()
    {
        return $this->belongsTo(MemberAdventurer::class, 'member_id');
    }

    public function staff()
    {
        return $this->belongsTo(StaffAdventurer::class, 'staff_id');
    }
}
