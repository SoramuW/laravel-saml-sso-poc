<?php

use App\Http\Controllers\SamlController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));
Route::get('/saml/login', [SamlController::class, 'login'])->middleware('throttle:30,1');
Route::post('/saml/acs', [SamlController::class, 'acs'])->middleware('throttle:30,1');
Route::get('/saml/metadata', [SamlController::class, 'metadata']);
Route::get('/saml/logout', [SamlController::class, 'logout'])->middleware('auth');
Route::match(['get', 'post'], '/saml/sls', [SamlController::class, 'sls'])->middleware('throttle:30,1');
