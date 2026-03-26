<?php

use App\Http\Controllers\AlbumController;
use App\Http\Controllers\AlbumGeniusController;
use App\Http\Controllers\ArtistController;
use App\Http\Controllers\ArtistGeniusController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\TrackController;
use App\Http\Controllers\TrackGeniusController;
use App\Http\Controllers\TrackLikeController;
use App\Http\Controllers\TrackPlayController;
use App\Http\Controllers\TrackStreamController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/search', [SearchController::class, 'index'])->name('search');
Route::get('/artists/{artist:slug}', [ArtistController::class, 'show'])->name('artists.show');
Route::get('/artists/{artist:slug}/genius', ArtistGeniusController::class)->name('artists.genius');
Route::get('/albums/{album:slug}', [AlbumController::class, 'show'])->name('albums.show');
Route::get('/albums/{album:slug}/genius', AlbumGeniusController::class)->name('albums.genius');
Route::get('/tracks/{track}', [TrackController::class, 'show'])->name('tracks.show');
Route::get('/tracks/{track}/genius', TrackGeniusController::class)->name('tracks.genius');
Route::get('/tracks/{track}/stream', TrackStreamController::class)->name('tracks.stream');
Route::post('/tracks/{track}/play', TrackPlayController::class)->name('tracks.play');

Route::middleware('guest')->group(function (): void {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/library/tracks', [LibraryController::class, 'index'])->name('library.tracks');
    Route::post('/tracks/{track}/like', [TrackLikeController::class, 'store'])->name('tracks.like');
    Route::delete('/tracks/{track}/like', [TrackLikeController::class, 'destroy'])->name('tracks.unlike');
});
