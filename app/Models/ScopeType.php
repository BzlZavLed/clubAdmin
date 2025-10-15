<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class ScopeType extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'club_id', 'value', 'label', 'status', 'created_by',
    ];
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
    protected $casts = [
        'club_id' => 'integer',
        'created_by' => 'integer',
    ];

    public function club() { return $this->belongsTo(Club::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
