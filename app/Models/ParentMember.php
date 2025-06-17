<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParentMember extends Model
{
    protected $fillable = [
        'user_id',
        'member_id',
        'club_id',
        'church_id',
    ];


    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class);
    }

    /**
     * Dynamically fetch the associated member record based on club type.
     */
    public function member()
    {
        switch ($this->club->club_type) {
            case 'adventurer':
                return MemberAdventurer::find($this->member_id);
            /* case 'pathfinder':
                return MemberPathfinder::find($this->member_id);
            case 'guide':
                return MemberGuide::find($this->member_id); */
            default:
                return null;
        }
    }
}
