<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use App\Http\Controllers\WebinarController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

/*
 * The comp's breadcrumb reads Home › Live Webinars › Human Resources › Title,
 * which suggests /live-webinars/{industry}/{slug}. That is left until P2 builds
 * the catalogue those first two segments link to — inventing the nesting now
 * would mean guessing how type and industry route, then redirecting away from a
 * URL that had already been indexed. §5.6 has a `redirects` table for the move
 * when it happens, and this slug stays the canonical tail of it either way.
 */
Route::get('/webinars/{webinar:slug}', WebinarController::class)->name('webinars.show');
