<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdventurerQuarterlyReport extends Model
{
    public const PERIOD_SEP_OCT = 'sep_oct';

    public const PERIOD_NOV_DEC = 'nov_dec';

    public const PERIOD_JAN_FEB = 'jan_feb';

    public const PERIOD_MAR_APR = 'mar_apr';

    public const PERIODS = [
        self::PERIOD_SEP_OCT,
        self::PERIOD_NOV_DEC,
        self::PERIOD_JAN_FEB,
        self::PERIOD_MAR_APR,
    ];

    protected $guarded = [];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'submitted_at' => 'datetime',
        'submitted_on_time' => 'boolean',
        'class_a_uniform_worn' => 'boolean',
        'curriculum_taught' => 'boolean',
        'attendance_percentage' => 'decimal:2',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
