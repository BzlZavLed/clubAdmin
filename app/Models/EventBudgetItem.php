<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class EventBudgetItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'event_id',
        'expense_id',
        'reimbursement_expense_id',
        'category',
        'description',
        'qty',
        'unit_cost',
        'total',
        'funding_source',
        'expense_date',
        'notes',
        'receipt_path',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'total' => 'decimal:2',
        'expense_date' => 'date',
    ];

    protected $appends = [
        'receipt_url',
    ];

    protected static function booted(): void
    {
        static::saving(function (EventBudgetItem $item) {
            $qty = (float) ($item->qty ?? 0);
            $unit = (float) ($item->unit_cost ?? 0);
            $item->total = round($qty * $unit, 2);
        });
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function reimbursementExpense()
    {
        return $this->belongsTo(Expense::class, 'reimbursement_expense_id');
    }

    public function getReceiptUrlAttribute(): ?string
    {
        if (!$this->receipt_path) {
            return null;
        }

        $relative = Storage::disk('public')->url($this->receipt_path);
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
