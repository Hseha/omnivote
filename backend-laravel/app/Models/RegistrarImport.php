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
        'grade_level',
        'role',
    ];
}