<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class Expense extends Model
{
    protected $fillable = [
        'club_id',
        'pay_to',
        'payment_concept_id',
        'payee_id',
        'amount',
        'expense_date',
        'description',
        'reimbursed_to',
        'created_by_user_id',
        'status',
        'receipt_path',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
    ];

    protected $appends = ['receipt_url'];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function getReceiptUrlAttribute(): ?string
    {
        if (!$this->receipt_path) {
            return null;
        }

        $relative = Storage::disk('public')->url($this->receipt_path);
        $host = request()?->getSchemeAndHttpHost();

        if ($host) {
            // If storage returned an absolute URL, replace host with the current request host (keeps dev ports like :8000)
            if (Str::startsWith($relative, ['http://', 'https://'])) {
                $path = parse_url($relative, PHP_URL_PATH) ?? $relative;
                return rtrim($host, '/') . '/' . ltrim($path, '/');
            }

            return rtrim($host, '/') . '/' . ltrim(Str::start($relative, '/'), '/');
        }

        return URL::to($relative);
    }
}
