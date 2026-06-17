<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class PathfinderMonthlyReportAttachment extends Model
{
    public const KIND_VOLUNTEER_PROOF = 'volunteer_proof';
    public const KIND_ACTIVITY_PHOTO = 'activity_photo';

    protected $fillable = [
        'pathfinder_monthly_report_id',
        'kind',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
    ];

    protected $appends = [
        'url',
    ];

    public function report()
    {
        return $this->belongsTo(PathfinderMonthlyReport::class, 'pathfinder_monthly_report_id');
    }

    public function getUrlAttribute(): ?string
    {
        if (!$this->path) {
            return null;
        }

        $relative = Storage::disk($this->disk ?: 'public')->url($this->path);
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
