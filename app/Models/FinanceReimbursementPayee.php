<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceReimbursementPayee extends Model
{
    protected $fillable = [
        'club_id',
        'name',
        'phone',
        'email',
        'created_by_user_id',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
