<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $fillable = [
        'department_id',
        'name',
        'sort_order',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}