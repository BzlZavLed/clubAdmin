<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterGuideMemberFormSchema extends Model
{
    protected $fillable = [
        'club_id',
        'schema_json',
        'updated_by',
    ];

    protected $casts = [
        'schema_json' => 'array',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
