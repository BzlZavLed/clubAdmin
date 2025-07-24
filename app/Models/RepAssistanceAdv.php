<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RepAssistanceAdv extends Model
{
    use HasFactory;
    protected $table = 'rep_assistance_adv';

    protected $fillable = [
        'month',
        'year',
        'date',
        'class_name',
        'class_id',
        'staff_name',
        'staff_id',
        'church',
        'church_id',
        'district',
        'club_id'
    ];

    public function merits()
    {
        return $this->hasOne(RepAssistanceAdvMerit::class, 'report_id');
    }
}
