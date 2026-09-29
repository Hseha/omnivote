<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $fillable = [
        'name',
        'code',
        'sort_order',
    ];

    public function courses()
    {
        return $this->hasMany(Course::class)->orderBy('sort_order');
    }

    public function aliases()
    {
        return $this->hasMany(DepartmentAlias::class);
    }
}