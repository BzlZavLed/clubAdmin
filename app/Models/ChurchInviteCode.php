<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class ChurchInviteCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'church_id',
        'code',
        'uses_left',
        'expires_at',
        'status',
        'created_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public static function generateCode(): string
    {
        return Str::upper(Str::random(10));
    }
}
