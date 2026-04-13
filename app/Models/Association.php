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
    ];

    public function union()
    {
        return $this->belongsTo(Union::class);
    }

    public function districts()
    {
        return $this->hasMany(District::class);
    }
}
