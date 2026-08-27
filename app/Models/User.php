<?php

namespace App\Models;

use App\Services\Mail\MailerService;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailBehavior;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, MustVerifyEmailBehavior, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'profile_type',
        'role_key',
        'scope_type',
        'scope_id',
        'sub_role',
        'church_name',
        'church_id',
        'club_id',
        'status',
        'parent_activation_method',
        'secure_enrollment_link_id',
        'enrollment_confirmed_at',
        'enrollment_confirmed_by',
        'must_change_password',
        'last_seen_at',
        'mobile_member_id',
        'privacy_consent_at',
        'privacy_notice_version',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'last_seen_at' => 'datetime',
            'enrollment_confirmed_at' => 'datetime',
            'privacy_consent_at' => 'datetime',
        ];
    }

    public function clubs()
    {
        return $this->belongsToMany(Club::class)
            ->withPivot('status')
            ->withTimestamps();
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function staff()
    {
        return $this->hasOne(Staff::class);
    }

    public function staffClass()
    {
        return $this->hasOneThrough(
            ClubClass::class,
            Staff::class,
            'user_id',      // Staff.user_id
            'id',           // ClubClass primary key
            'id',           // User primary key
            'assigned_class' // Staff.assigned_class -> ClubClass.id
        );
    }

    public function church()
    {
        return $this->belongsTo(Church::class);
    }

    public function mobileMember()
    {
        return $this->belongsTo(Member::class, 'mobile_member_id');
    }

    public function secureEnrollmentLink()
    {
        return $this->belongsTo(ClubParentEnrollmentLink::class, 'secure_enrollment_link_id');
    }

    public function isDirectorActivatedParent(): bool
    {
        return $this->profile_type === 'parent' && $this->parent_activation_method === 'director';
    }

    public function canAccessParentPortal(): bool
    {
        if ($this->profile_type !== 'parent') {
            return true;
        }

        if ($this->status !== null && $this->status !== 'active') {
            return false;
        }

        return ! $this->secure_enrollment_link_id
            || $this->hasVerifiedEmail()
            || $this->isDirectorActivatedParent();
    }

    public function canSelfServiceCredentials(): bool
    {
        return $this->profile_type !== 'parent'
            || (! $this->isDirectorActivatedParent() && $this->hasVerifiedEmail());
    }

    public function sendEmailVerificationNotification(): void
    {
        if ($this->profile_type === 'parent') {
            app(MailerService::class)->sendParentEmailVerification($this);

            return;
        }

        $this->notify(new VerifyEmail);
    }

    public function sendPasswordResetNotification($token): void
    {
        if ($this->profile_type === 'parent') {
            app(MailerService::class)->sendParentPasswordReset($this, (string) $token);

            return;
        }

        $this->notify(new ResetPassword($token));
    }

    public function createdEvents()
    {
        return $this->hasMany(Event::class, 'created_by_user_id');
    }
}
