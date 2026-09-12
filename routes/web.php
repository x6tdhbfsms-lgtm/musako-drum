<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/me', fn () => response()->json(request()->user()->only(['id', 'name', 'email', 'role'])));
    Route::get('/student/dashboard', fn () => response()->json(['dashboard' => 'student']))->middleware('role:student');
    Route::get('/staff/dashboard', fn () => response()->json(['dashboard' => 'staff']))->middleware('role:teacher,admin');
});
