<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseAlias extends Model
{
    protected $fillable = [
        'course_id',
        'alias',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}