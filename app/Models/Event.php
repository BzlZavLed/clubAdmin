<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'club_id',
        'created_by_user_id',
        'title',
        'event_type',
        'start_at',
        'end_at',
        'timezone',
        'location_name',
        'location_address',
        'status',
        'budget_estimated_total',
        'budget_actual_total',
        'requires_approval',
        'is_payable',
        'payment_amount',
        'payment_concept_id',
        'risk_level',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'requires_approval' => 'boolean',
        'is_payable' => 'boolean',
        'budget_estimated_total' => 'decimal:2',
        'budget_actual_total' => 'decimal:2',
        'payment_amount' => 'decimal:2',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function plan()
    {
        return $this->hasOne(EventPlan::class);
    }

    public function tasks()
    {
        return $this->hasMany(EventTask::class);
    }

    public function budgetItems()
    {
        return $this->hasMany(EventBudgetItem::class);
    }

    public function participants()
    {
        return $this->hasMany(EventParticipant::class);
    }

    public function documents()
    {
        return $this->hasMany(EventDocument::class);
    }

    public function placeOptions()
    {
        return $this->hasMany(EventPlaceOption::class);
    }

    public function drivers()
    {
        return $this->hasMany(EventDriver::class);
    }

    public function vehicles()
    {
        return $this->hasMany(EventVehicle::class);
    }

    public function paymentConcept()
    {
        return $this->belongsTo(PaymentConcept::class);
    }
}
