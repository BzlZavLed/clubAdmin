<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class FundraiserInvestmentReceipt extends Model
{
    protected $fillable = [
        'fundraiser_event_id',
        'expense_id',
        'path',
        'original_name',
        'mime_type',
        'uploaded_by_user_id',
    ];

    protected $appends = ['url'];

    public function fundraiserEvent()
    {
        return $this->belongsTo(FundraiserEvent::class);
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function getUrlAttribute(): ?string
    {
        if (!$this->path) {
            return null;
        }

        $relative = Storage::disk('public')->url($this->path);
        $host = request()?->getSchemeAndHttpHost();

        if (!$host) {
            return URL::to($relative);
        }

        if (Str::startsWith($relative, ['http://', 'https://'])) {
            $urlPath = parse_url($relative, PHP_URL_PATH) ?? $relative;

            return rtrim($host, '/') . '/' . ltrim($urlPath, '/');
        }

        return rtrim($host, '/') . '/' . ltrim(Str::start($relative, '/'), '/');
    }
}
