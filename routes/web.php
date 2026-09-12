<?php

use App\Enums\UserRole;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Staff\DashboardController as StaffDashboardController;
use App\Http\Controllers\Staff\LessonSlotController;
use App\Http\Controllers\Staff\ReservationReviewController;
use App\Http\Controllers\Student\AvailableLessonSlotController;
use App\Http\Controllers\Student\DashboardController as StudentDashboardController;
use App\Http\Controllers\Student\ReservationRequestController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    return auth()->user()->role === UserRole::Student
        ? redirect()->route('student.dashboard')
        : redirect()->route('staff.dashboard');
})->name('home');

Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/me', fn () => response()->json(request()->user()->only(['id', 'name', 'email', 'role'])));
    Route::prefix('student')->name('student.')->middleware('role:student')->group(function () {
        Route::get('/dashboard', StudentDashboardController::class)->name('dashboard');
        Route::get('/lesson-slots', AvailableLessonSlotController::class)->name('lesson-slots.index');
        Route::get('/reservations', [ReservationRequestController::class, 'index'])->name('reservations.index');
        Route::post('/lesson-slots/{lesson_slot}/reservations', [ReservationRequestController::class, 'store'])->name('reservations.store');
        Route::delete('/reservations/{reservation_request}', [ReservationRequestController::class, 'destroy'])->name('reservations.destroy');
    });

    Route::prefix('staff')->name('staff.')->middleware('role:teacher,admin')->group(function () {
        Route::get('/dashboard', StaffDashboardController::class)->name('dashboard');
        Route::resource('lesson-slots', LessonSlotController::class)->except('show');
        Route::get('/reservations', [ReservationReviewController::class, 'index'])->name('reservations.index');
        Route::patch('/reservations/{reservation_request}', [ReservationReviewController::class, 'update'])->name('reservations.update');
    });
});
