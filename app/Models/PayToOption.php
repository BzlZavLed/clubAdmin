<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class PayToOption extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'club_id', 'value', 'label', 'status', 'created_by',
    ];

    protected $casts = [
        'club_id' => 'integer',
        'created_by' => 'integer',
    ];
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
    public function club() { return $this->belongsTo(Club::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
