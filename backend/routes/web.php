<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Named 'login' route required by auth middleware redirect when unauthenticated
Route::get('/login', function () {
    return redirect('http://localhost:5174/login');
})->name('login');
