<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\ChurchInviteCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Church extends Model
{
    use HasFactory;

    protected $fillable = [
        'district_id',
        'church_name',
        'address',
        'ethnicity',
        'phone_number',
        'email',
        'pastor_name',
        'pastor_email',
        'conference',
    ];
    public function clubs()
    {
        return $this->hasMany(Club::class);
    }

    public function district()
    {
        return $this->belongsTo(District::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function inviteCode()
    {
        return $this->hasOne(ChurchInviteCode::class);
    }
}
