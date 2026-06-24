<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
// use Illuminate\Database\Eloquent\SoftDeletes;

class SystemSetting extends Model
{
    use HasUuids;

    protected $fillable = [
        'school_year', 
        'term', 
        'maintenance_mode', 
        'is_active', 
        'maintenance_started_at'
    ];

    protected $casts = [
        'maintenance_mode' => 'boolean',
        'is_active' => 'boolean',
        'maintenance_started_at' => 'datetime',
    ];
}