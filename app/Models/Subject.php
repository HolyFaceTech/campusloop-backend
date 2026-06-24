<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subject extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['code', 'description', 'strand_id', 'grade_level', 'term'];

    public function strand()
    {
        return $this->belongsTo(Strand::class);
    }

    public function classrooms()
    {
        return $this->hasMany(Classroom::class);
    }
}
