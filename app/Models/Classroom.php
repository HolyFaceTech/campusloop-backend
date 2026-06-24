<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Classroom extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'creator_id', 'section', 'strand_id', 'grade_level', 
        'subject_id', 'capacity', 'schedule', 'color_bg', 
        'code', 'school_year', 'term'
    ];

    protected $casts = [
        'schedule' => 'array',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id'); 
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class); 
    }

    public function strand()
    {
        return $this->belongsTo(Strand::class);
    }

    // BelongsToMany connection para sa mga students (gamit ang custom pivot table)
    public function students()
    {
        return $this->belongsToMany(User::class, 'classroom_student', 'classroom_id', 'student_id')
                    ->withPivot('id', 'status', 'deleted_at')
                    ->withTimestamps();
    }

    public function classworks()
    {
        return $this->hasMany(Classwork::class);
    }
}
