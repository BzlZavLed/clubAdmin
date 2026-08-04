<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class AdventurerYearlyApplicationSignature extends Model
{
    public const ROLE_DIRECTOR = 'director';

    public const ROLE_PASTOR = 'pastor';

    public const ROLE_HEAD_ELDER = 'head_elder';

    public const ROLE_CHURCH_CLERK = 'church_clerk';

    protected $fillable = [
        'adventurer_yearly_application_id',
        'role',
        'signer_name',
        'signer_email',
        'signature_type',
        'signature_text',
        'signature_path',
        'request_token',
        'requested_at',
        'signed_at',
        'expires_at',
        'status',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'signed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected $appends = ['signature_url'];

    public function application()
    {
        return $this->belongsTo(AdventurerYearlyApplication::class, 'adventurer_yearly_application_id');
    }

    public function getSignatureUrlAttribute(): ?string
    {
        if (! $this->signature_path) {
            return null;
        }

        $relative = Storage::disk('public')->url($this->signature_path);
        $host = request()?->getSchemeAndHttpHost();

        if ($host) {
            if (Str::startsWith($relative, ['http://', 'https://'])) {
                $urlPath = parse_url($relative, PHP_URL_PATH) ?? $relative;

                return rtrim($host, '/').'/'.ltrim($urlPath, '/');
            }

            return rtrim($host, '/').'/'.ltrim(Str::start($relative, '/'), '/');
        }

        return URL::to($relative);
    }
}
