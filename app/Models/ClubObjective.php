<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubObjective extends Model
{
    protected $fillable = [
        'club_id',
        'name',
        'annual_evaluation_metric',
        'description',
        'department_id',
        'external_objective_id',
        'status',
        'created_by',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }
}
