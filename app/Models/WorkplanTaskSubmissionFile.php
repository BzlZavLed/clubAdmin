<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkplanTaskSubmissionFile extends Model
{
    protected $fillable = [
        'workplan_task_submission_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'evidence_type',
        'metadata',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'metadata' => 'array',
    ];

    public function submission()
    {
        return $this->belongsTo(WorkplanTaskSubmission::class, 'workplan_task_submission_id');
    }
}
