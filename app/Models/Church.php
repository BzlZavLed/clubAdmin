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
