<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Event extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PLAN_FINALIZED = 'plan_finalized';
    public const STATUS_ONGOING = 'ongoing';
    public const STATUS_PAST = 'past';

    protected $fillable = [
        'club_id',
        'created_by_user_id',
        'title',
        'description',
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

    protected $appends = [
        'effective_status',
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

    public static function editableStatuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_PLAN_FINALIZED,
        ];
    }

    public function getEffectiveStatusAttribute(): string
    {
        $now = Carbon::now($this->timezone ?: config('app.timezone'));
        $startAt = $this->start_at ? Carbon::parse($this->start_at)->setTimezone($this->timezone ?: config('app.timezone')) : null;
        $endAt = $this->end_at ? Carbon::parse($this->end_at)->setTimezone($this->timezone ?: config('app.timezone')) : null;

        if ($endAt && $now->greaterThanOrEqualTo($endAt)) {
            return self::STATUS_PAST;
        }

        if ($startAt && $now->greaterThanOrEqualTo($startAt)) {
            return self::STATUS_ONGOING;
        }

        $status = strtolower((string) $this->status);

        return in_array($status, self::editableStatuses(), true)
            ? $status
            : self::STATUS_DRAFT;
    }
}
