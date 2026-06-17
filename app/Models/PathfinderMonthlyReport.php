<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class PathfinderMonthlyReport extends Model
{
    protected $fillable = [
        'club_id',
        'created_by_user_id',
        'updated_by_user_id',
        'report_year',
        'report_month',
        'full_name',
        'email',
        'area',
        'church_and_club_name',
        'pathfinders_count',
        'tlt_count',
        'staff_count',
        'meetings_count',
        'bible_studies_count',
        'baptisms_count',
        'campouts_count',
        'field_trips_count',
        'honors_completed_count',
        'honors_completed_list',
        'outreach_activities',
        'notable_activities',
        'may_share_photos',
        'pdf_path',
        'pdf_file_name',
        'last_sent_to_email',
        'delivery_status',
        'sent_at',
    ];

    protected $casts = [
        'may_share_photos' => 'boolean',
        'sent_at' => 'datetime',
    ];

    protected $appends = [
        'pdf_url',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function attachments()
    {
        return $this->hasMany(PathfinderMonthlyReportAttachment::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function getPdfUrlAttribute(): ?string
    {
        if (!$this->pdf_path) {
            return null;
        }

        return $this->buildPublicUrl($this->pdf_path);
    }

    public function buildPublicUrl(string $path): string
    {
        $relative = Storage::disk('public')->url($path);
        $host = request()?->getSchemeAndHttpHost();

        if ($host) {
            if (Str::startsWith($relative, ['http://', 'https://'])) {
                $urlPath = parse_url($relative, PHP_URL_PATH) ?? $relative;

                return rtrim($host, '/') . '/' . ltrim($urlPath, '/');
            }

            return rtrim($host, '/') . '/' . ltrim(Str::start($relative, '/'), '/');
        }

        return URL::to($relative);
    }
}
