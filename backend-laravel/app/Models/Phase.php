<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Phase extends Model
{
    protected $table = 'phases';
    protected $fillable = ['name', 'description'];

    public static function current(): ?self
    {
        // For now, assume first non-null phase row indicates current; in production store active flag
        return self::first();
    }
}
