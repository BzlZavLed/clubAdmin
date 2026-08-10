<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdventurerInductionRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'induction_date' => 'date:Y-m-d',
        'received_at' => 'datetime',
        'emailed_at' => 'datetime',
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
