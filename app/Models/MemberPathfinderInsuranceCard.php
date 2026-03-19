<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class MemberPathfinderInsuranceCard extends Model
{
    use HasFactory;

    protected $table = 'member_pathfinder_insurance_cards';

    protected $fillable = [
        'member_pathfinder_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'uploaded_by',
    ];

    protected $appends = [
        'url',
    ];

    public function memberPathfinder()
    {
        return $this->belongsTo(MemberPathfinder::class, 'member_pathfinder_id');
    }

    public function getUrlAttribute(): ?string
    {
        if (!$this->path) return null;

        $storageUrl = Storage::disk($this->disk ?: 'public')->url($this->path);
        $parsedPath = parse_url($storageUrl, PHP_URL_PATH) ?: $storageUrl;
        $parsedQuery = parse_url($storageUrl, PHP_URL_QUERY);

        $baseUrl = request()?->getSchemeAndHttpHost() ?: rtrim((string) config('app.url'), '/');
        $url = rtrim($baseUrl, '/') . '/' . ltrim($parsedPath, '/');

        if ($parsedQuery) {
            $url .= '?' . $parsedQuery;
        }

        return $url;
    }
}
