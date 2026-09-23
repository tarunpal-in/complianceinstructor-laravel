<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use App\Http\Controllers\PageController;
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

/*
 * Client-built marketing pages — §5.6 and §2's second leverage decision.
 *
 * Last, and deliberately a catch-all: `/about`, `/privacy-policy` and whatever
 * else the client makes are all one route, because inventing a route per page
 * is the thing the page builder exists to stop.
 *
 * Being last is what keeps it safe — Laravel matches in order, so every route
 * above wins. Page::RESERVED_SLUGS covers the rest (the admin panel, Fortify's
 * auth routes, Horizon), refusing them at the point a slug is saved rather than
 * leaving a page to be created that could never be reached.
 *
 * The {slug} pattern excludes slashes by default, so this claims one path
 * segment only. Nested pages, if they are ever wanted, are a deliberate change
 * rather than something that happens by accident.
 */
Route::get('/{slug}', PageController::class)->name('pages.show');
