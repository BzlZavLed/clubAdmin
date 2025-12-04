<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkplanRule extends Model
{
    protected $fillable = [
        'workplan_id',
        'meeting_type',
        'nth_week',
        'note',
    ];

    public function workplan()
    {
        return $this->belongsTo(Workplan::class);
    }
}
