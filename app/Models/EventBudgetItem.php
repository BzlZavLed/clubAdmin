<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventBudgetItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'event_id',
        'category',
        'description',
        'qty',
        'unit_cost',
        'total',
        'funding_source',
        'notes',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'total' => 'decimal:2',
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
}
