<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class District extends Model
{
    use HasFactory;

    protected $fillable = [
        'association_id',
        'name',
        'status',
    ];

    public function association()
    {
        return $this->belongsTo(Association::class);
    }

    public function churches()
    {
        return $this->hasMany(Church::class);
    }
}
