<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssociationEvaluator extends Model
{
    protected $fillable = [
        'association_id',
        'name',
        'email',
        'notes',
    ];

    public function association()
    {
        return $this->belongsTo(Association::class);
    }
}
