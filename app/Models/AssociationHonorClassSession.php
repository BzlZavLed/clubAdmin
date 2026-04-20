<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssociationHonorClassSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'association_id',
        'club_type',
        'class_name',
        'title',
        'session_date',
        'location',
        'notes',
        'status',
    ];

    protected $casts = [
        'session_date' => 'date',
    ];

    public function association()
    {
        return $this->belongsTo(Association::class);
    }
}
