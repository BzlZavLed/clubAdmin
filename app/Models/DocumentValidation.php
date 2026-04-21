<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentValidation extends Model
{
    protected $fillable = [
        'checksum',
        'document_type',
        'title',
        'generated_by_user_id',
        'metadata',
        'document_snapshot',
        'generated_at',
        'last_validated_at',
        'validation_count',
    ];

    protected $casts = [
        'metadata' => 'array',
        'document_snapshot' => 'array',
        'generated_at' => 'datetime',
        'last_validated_at' => 'datetime',
    ];

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }
}
