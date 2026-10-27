<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/dashboard'));

if (app()->environment('local')) {
    Route::view('/design', 'design');
}
