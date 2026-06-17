<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PathfinderAnnualApplication extends Model
{
    protected $fillable = [
        'club_id',
        'created_by_user_id',
        'updated_by_user_id',
        'application_year',
        'due_date',
        'sponsoring_church',
        'pastor',
        'elected_club_director',
        'mailing_address',
        'director_phone_number',
        'home_phone',
        'cell_phone',
        'email_address',
        'church_pastor_signature',
        'head_elder_signature',
        'church_clerk_signature',
        'club_director_signature',
        'board_approval_date',
        'other_board_members',
        'pdf_path',
        'pdf_file_name',
        'last_sent_to_email',
        'delivery_status',
        'sent_at',
    ];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'board_approval_date' => 'date:Y-m-d',
        'other_board_members' => 'array',
        'sent_at' => 'datetime',
    ];

    protected $appends = [
        'pdf_url',
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
        return $this->hasMany(PathfinderAnnualApplicationSignature::class);
    }

    public function getPdfUrlAttribute(): ?string
    {
        return $this->pdf_path ? Storage::disk('public')->url($this->pdf_path) : null;
    }
}
