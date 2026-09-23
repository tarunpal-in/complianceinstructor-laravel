<?php

declare(strict_types=1);

use App\Http\Controllers\BlogController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PagePreviewController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PostPreviewController;
use App\Http\Controllers\WebinarController;
use App\Models\Post;
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
 * The blog — §5.7 and §6.
 *
 * Its own `/blog` prefix rather than living under the page catch-all, because
 * an article and a marketing page are different things with different
 * templates, and one namespace for both would mean a post and a page competing
 * for the same slug.
 */
Route::get('/'.Post::PATH_PREFIX, BlogController::class)->name('blog.index');

Route::get('/'.Post::PATH_PREFIX.'/preview/{post}', PostPreviewController::class)
    ->middleware('signed')
    ->name('blog.preview');

/*
 * After the preview route, so `/blog/preview/…` is not read as an article
 * whose slug happens to be "preview".
 */
Route::get('/'.Post::PATH_PREFIX.'/{slug}', PostController::class)->name('blog.show');

/*
 * A draft, shown to somebody who was sent the link.
 *
 * Before the catch-all so the path is unambiguous, and `signed` is the whole
 * access control — §9 makes signed expiring URLs the rule for anything that
 * must not be reachable by guessing. No auth: the point is to send it to a
 * client for review, and requiring an admin account would mean either no review
 * or an account they should not have.
 */
Route::get('/preview/{page}', PagePreviewController::class)
    ->middleware('signed')
    ->name('pages.preview');

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
