<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceMovementConceptOverride extends Model
{
    protected $fillable = [
        'club_id',
        'movement_type',
        'movement_id',
        'original_concept',
        'display_concept',
        'updated_by_user_id',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
