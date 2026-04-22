<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Association extends Model
{
    use HasFactory;

    protected $fillable = [
        'union_id',
        'name',
        'status',
        'insurance_payment_amount',
    ];

    protected $casts = [
        'insurance_payment_amount' => 'decimal:2',
    ];

    public function union()
    {
        return $this->belongsTo(Union::class);
    }

    public function districts()
    {
        return $this->hasMany(District::class);
    }

    public function honorClassSessions()
    {
        return $this->hasMany(AssociationHonorClassSession::class)->orderBy('session_date')->orderBy('id');
    }

    public function evaluators()
    {
        return $this->hasMany(\App\Models\AssociationEvaluator::class)->orderBy('name');
    }

    public function workplanEvents()
    {
        return $this->hasMany(AssociationWorkplanEvent::class);
    }

    public function workplanPublications()
    {
        return $this->hasMany(AssociationWorkplanPublication::class);
    }
}
