<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClassInvestitureRequirement extends Model
{
    use HasFactory;

    protected $fillable = [
        'club_class_id',
        'title',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function clubClass()
    {
        return $this->belongsTo(ClubClass::class);
    }
}

