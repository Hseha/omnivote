<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Student ID format gate
    |--------------------------------------------------------------------------
    |
    | PCRE pattern every registrar-import student_id must match. The import
    | NEVER guesses the school's real scheme: this default is deliberately
    | permissive (printable ASCII only — Excel corruption like 2.024E+11
    | still passes here and is caught by the exponential-notation check in
    | RegistrarImportController::studentIdFormatProblem()).
    |
    | OPEN QUESTION: tighten this to the confirmed scheme, e.g.
    |   'student_id_pattern' => '/^\d{4}-\d{4}$/',
    | once the registrar confirms the exact format/length. Until then, do
    | not invent a stricter rule.
    |
    */

    'student_id_pattern' => env('REGISTRAR_STUDENT_ID_PATTERN', '/^[\x20-\x7E]+$/'),

];
