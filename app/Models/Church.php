<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Church extends Model
{
    protected $fillable = [
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
    public function users()
    {
        return $this->hasMany(User::class);
    }
}
