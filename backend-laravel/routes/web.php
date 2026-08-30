<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;

// CSRF cookie endpoint used by first-party SPA to initiate stateful session
Route::get('/sanctum/csrf-cookie', [CsrfCookieController::class, 'show']);
