<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MemberPathfinder extends Model
{
    use HasFactory;

    protected $table = 'members_pathfinders';

    protected $fillable = [
        'club_id',
        'member_id',
        'source_temp_member_pathfinder_id',
        'club_name',
        'director_name',
        'church_name',
        'applicant_name',
        'birthdate',
        'grade',
        'mailing_address',
        'city',
        'state',
        'zip',
        'school',
        'cell_number',
        'email_address',
        'father_guardian_name',
        'father_guardian_email',
        'father_guardian_phone',
        'mother_guardian_name',
        'mother_guardian_email',
        'mother_guardian_phone',
        'pickup_authorized_people',
        'consent_acknowledged',
        'photo_release',
        'health_history',
        'disabilities',
        'medication_allergies',
        'food_allergies',
        'dietary_considerations',
        'physical_restrictions',
        'immunization_notes',
        'current_medications',
        'physician_name',
        'physician_phone',
        'emergency_contact_name',
        'emergency_contact_phone',
        'insurance_provider',
        'insurance_number',
        'parent_guardian_signature',
        'signed_at',
        'additional_signatures',
        'application_data',
        'status',

        // Legacy aliases preserved during transition from temp_member_pathfinder.
        'nombre',
        'dob',
        'phone',
        'email',
        'father_name',
        'father_phone',
    ];

    protected $casts = [
        'birthdate' => 'date',
        'signed_at' => 'date',
        'pickup_authorized_people' => 'array',
        'additional_signatures' => 'array',
        'application_data' => 'array',
        'consent_acknowledged' => 'boolean',
        'photo_release' => 'boolean',
    ];

    protected $appends = [
        'nombre',
        'dob',
        'phone',
        'email',
        'father_name',
        'father_phone',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function getNombreAttribute(): ?string
    {
        return $this->applicant_name;
    }

    public function setNombreAttribute(?string $value): void
    {
        $this->attributes['applicant_name'] = $value;
    }

    public function getDobAttribute(): ?string
    {
        return $this->birthdate?->toDateString() ?? $this->attributes['birthdate'] ?? null;
    }

    public function setDobAttribute($value): void
    {
        $this->attributes['birthdate'] = $value;
    }

    public function getPhoneAttribute(): ?string
    {
        return $this->cell_number;
    }

    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['cell_number'] = $value;
    }

    public function getEmailAttribute(): ?string
    {
        return $this->email_address;
    }

    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email_address'] = $value;
    }

    public function getFatherNameAttribute(): ?string
    {
        return $this->father_guardian_name;
    }

    public function setFatherNameAttribute(?string $value): void
    {
        $this->attributes['father_guardian_name'] = $value;
    }

    public function getFatherPhoneAttribute(): ?string
    {
        return $this->father_guardian_phone;
    }

    public function setFatherPhoneAttribute(?string $value): void
    {
        $this->attributes['father_guardian_phone'] = $value;
    }
}
