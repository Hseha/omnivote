<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepartmentAlias extends Model
{
    protected $fillable = [
        'department_id',
        'alias',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}