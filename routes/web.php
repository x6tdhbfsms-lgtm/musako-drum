<?php

use App\Enums\UserRole;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Staff\DashboardController as StaffDashboardController;
use App\Http\Controllers\Staff\LessonSlotController;
use App\Http\Controllers\Staff\MembershipStatusRequestController as StaffMembershipStatusRequestController;
use App\Http\Controllers\Staff\ReservationDetailController as StaffReservationDetailController;
use App\Http\Controllers\Staff\ReservationReviewController;
use App\Http\Controllers\Staff\TransferRequestController as StaffTransferRequestController;
use App\Http\Controllers\Student\AttendanceNoticeController;
use App\Http\Controllers\Student\AvailableLessonSlotController;
use App\Http\Controllers\Student\DashboardController as StudentDashboardController;
use App\Http\Controllers\Student\MembershipStatusRequestController as StudentMembershipStatusRequestController;
use App\Http\Controllers\Student\ReservationDetailController as StudentReservationDetailController;
use App\Http\Controllers\Student\ReservationRequestController;
use App\Http\Controllers\Student\TransferRequestController as StudentTransferRequestController;
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
        Route::get('/reservations/{reservation_request}', StudentReservationDetailController::class)->name('reservations.show');
        Route::post('/lesson-slots/{lesson_slot}/reservations', [ReservationRequestController::class, 'store'])->name('reservations.store');
        Route::delete('/reservations/{reservation_request}', [ReservationRequestController::class, 'destroy'])->name('reservations.destroy');
        Route::get('/attendance-notices', [AttendanceNoticeController::class, 'index'])->name('attendance-notices.index');
        Route::put('/reservations/{reservation_request}/attendance-notice', [AttendanceNoticeController::class, 'store'])->name('attendance-notices.store');
        Route::get('/transfer-requests', [StudentTransferRequestController::class, 'index'])->name('transfer-requests.index');
        Route::get('/reservations/{reservation_request}/transfer-request', [StudentTransferRequestController::class, 'create'])->name('transfer-requests.create');
        Route::post('/reservations/{reservation_request}/transfer-request', [StudentTransferRequestController::class, 'store'])->name('transfer-requests.store');
        Route::get('/membership-status-requests', [StudentMembershipStatusRequestController::class, 'index'])->name('membership-status-requests.index');
        Route::post('/membership-status-requests', [StudentMembershipStatusRequestController::class, 'store'])->name('membership-status-requests.store');
    });

    Route::prefix('staff')->name('staff.')->middleware('role:teacher,admin')->group(function () {
        Route::get('/dashboard', StaffDashboardController::class)->name('dashboard');
        Route::resource('lesson-slots', LessonSlotController::class)->except('show');
        Route::get('/reservations', [ReservationReviewController::class, 'index'])->name('reservations.index');
        Route::get('/reservations/{reservation_request}', StaffReservationDetailController::class)->name('reservations.show');
        Route::patch('/reservations/{reservation_request}', [ReservationReviewController::class, 'update'])->name('reservations.update');
        Route::get('/transfer-requests', [StaffTransferRequestController::class, 'index'])->name('transfer-requests.index');
        Route::get('/transfer-requests/{transfer_request}', [StaffTransferRequestController::class, 'show'])->name('transfer-requests.show');
        Route::patch('/transfer-requests/{transfer_request}', [StaffTransferRequestController::class, 'update'])->name('transfer-requests.update');
        Route::get('/membership-status-requests', [StaffMembershipStatusRequestController::class, 'index'])->name('membership-status-requests.index');
        Route::get('/membership-status-requests/{membership_status_request}', [StaffMembershipStatusRequestController::class, 'show'])->name('membership-status-requests.show');
        Route::patch('/membership-status-requests/{membership_status_request}', [StaffMembershipStatusRequestController::class, 'update'])->name('membership-status-requests.update');
    });
});
