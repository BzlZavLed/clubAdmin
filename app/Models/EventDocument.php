<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'event_id',
        'type',
        'title',
        'path',
        'uploaded_by_user_id',
        'meta_json',
    ];

    protected $casts = [
        'meta_json' => 'array',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
