<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdventurerYearlyApplication extends Model
{
    protected $fillable = [
        'club_id',
        'created_by_user_id',
        'updated_by_user_id',
        'application_year',
        'application_date',
        'club_name',
        'sponsoring_church',
        'pastor',
        'elected_club_director',
        'email_address',
        'cell_number',
        'home_address',
        'church_pastor_signature',
        'head_elder_signature',
        'church_clerk_signature',
        'club_director_signature',
        'signature_date',
        'other_board_members',
        'docx_path',
        'docx_file_name',
        'last_sent_to_email',
        'delivery_status',
        'sent_at',
    ];

    protected $casts = [
        'application_date' => 'date:Y-m-d',
        'signature_date' => 'date:Y-m-d',
        'other_board_members' => 'array',
        'sent_at' => 'datetime',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function signatures()
    {
        return $this->hasMany(AdventurerYearlyApplicationSignature::class);
    }
}
