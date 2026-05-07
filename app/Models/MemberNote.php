<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MemberNote extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'member_id',
        'district_id',
        'created_by',
        'updated_by',
        'subject',
        'body',
        'context',
        'color',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function district()
    {
        return $this->belongsTo(District::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
