<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single row from the Registrar CSV import — the eligibility feed that
 * backend registration, provisioning, and turnout figures are derived from.
 */
class RegistrarImport extends Model
{
    protected $table = 'registrar_imports';

    protected $fillable = [
        'student_id',
        'full_name',
        'email',
        'role',
        'year_level',
        'block_number',
        'department',
        'course',
        'needs_review',
        'review_reason',
        'activation_code_hash',
        'activation_code_issued_at',
        'activation_code_used_at',
    ];

    protected $hidden = [
        // Only the bcrypt hash is stored; it must never leave the server.
        'activation_code_hash',
    ];

    protected $casts = [
        'needs_review' => 'boolean',
        'activation_code_issued_at' => 'datetime',
        'activation_code_used_at' => 'datetime',
    ];
}
