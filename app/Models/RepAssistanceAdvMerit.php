<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RepAssistanceAdvMerit extends Model
{
    use HasFactory;
    protected $table = 'rep_assistance_adv_merits';

    protected $fillable = [
        'mem_adv_name',
        'mem_adv_id',
        'asistencia',
        'puntualidad',
        'uniforme',
        'conductor',
        'cuota',
        'report_id',
        'total',
        'cuota_amount', // Added for cuota amount
    ];

    public function report()
    {
        return $this->belongsTo(RepAssistanceAdv::class, 'report_id');
    }
}
