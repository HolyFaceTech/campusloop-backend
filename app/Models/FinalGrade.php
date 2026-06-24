<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinalGrade extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'student_id', 'subject_id', 'teacher_id', 'school_year', 
        'term', 'grade', 'status', 'admin_feedback'
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }
    
    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
    
    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }
}
