<?php

use App\Enums\UserRole;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\PublicAdmissionApplicationController;
use App\Http\Controllers\PublicTrialCancellationController;
use App\Http\Controllers\PublicTrialLessonController;
use App\Http\Controllers\Staff\AdmissionApplicationController as StaffAdmissionApplicationController;
use App\Http\Controllers\Staff\ContractChangeRequestController as StaffContractChangeRequestController;
use App\Http\Controllers\Staff\DashboardController as StaffDashboardController;
use App\Http\Controllers\Staff\InquiryController as StaffInquiryController;
use App\Http\Controllers\Staff\LessonSlotController;
use App\Http\Controllers\Staff\MembershipStatusRequestController as StaffMembershipStatusRequestController;
use App\Http\Controllers\Staff\PaymentMethodChangeRequestController as StaffPaymentMethodChangeRequestController;
use App\Http\Controllers\Staff\PersonalInformationChangeRequestController as StaffPersonalInformationChangeRequestController;
use App\Http\Controllers\Staff\PricingSettingController;
use App\Http\Controllers\Staff\ProcedureRequestController;
use App\Http\Controllers\Staff\ReservationDetailController as StaffReservationDetailController;
use App\Http\Controllers\Staff\ReservationReviewController;
use App\Http\Controllers\Staff\StudentController as StaffStudentController;
use App\Http\Controllers\Staff\TransferRequestController as StaffTransferRequestController;
use App\Http\Controllers\Staff\TrialLessonRequestController as StaffTrialLessonRequestController;
use App\Http\Controllers\Student\AttendanceNoticeController;
use App\Http\Controllers\Student\AvailableLessonSlotController;
use App\Http\Controllers\Student\ContractChangeRequestController as StudentContractChangeRequestController;
use App\Http\Controllers\Student\DashboardController as StudentDashboardController;
use App\Http\Controllers\Student\InquiryController as StudentInquiryController;
use App\Http\Controllers\Student\MembershipStatusRequestController as StudentMembershipStatusRequestController;
use App\Http\Controllers\Student\PaymentMethodChangeRequestController as StudentPaymentMethodChangeRequestController;
use App\Http\Controllers\Student\PersonalInformationChangeRequestController as StudentPersonalInformationChangeRequestController;
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
Route::get('/pricing', PricingController::class)->name('pricing');
Route::view('/privacy', 'public.privacy')->name('privacy');
Route::get('/trial-lessons', [PublicTrialLessonController::class, 'index'])->name('trial-lessons.index');
Route::post('/trial-lessons', [PublicTrialLessonController::class, 'store'])->middleware('throttle:trial-application')->name('trial-lessons.store');
Route::get('/trial-lessons/complete/{reference}', [PublicTrialLessonController::class, 'complete'])->name('trial-lessons.complete');
Route::get('/trial-lessons/{reference}/{token}', [PublicTrialCancellationController::class, 'show'])->name('trial-lessons.manage');
Route::delete('/trial-lessons/{reference}/{token}', [PublicTrialCancellationController::class, 'destroy'])->middleware('throttle:trial-application')->name('trial-lessons.cancel');
Route::get('/trial-lessons/{reference}/{token}/admission', [PublicAdmissionApplicationController::class, 'create'])->name('admissions.create');
Route::post('/trial-lessons/{reference}/{token}/admission', [PublicAdmissionApplicationController::class, 'store'])->middleware('throttle:admission-application')->name('admissions.store');
Route::get('/admissions/complete/{reference}', [PublicAdmissionApplicationController::class, 'complete'])->name('admissions.complete');
Route::get('/password/reset/{token}', [NewPasswordController::class, 'create'])->middleware('guest')->name('password.reset');
Route::post('/password/reset', [NewPasswordController::class, 'store'])->middleware(['guest', 'throttle:6,1'])->name('password.update');

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
        Route::get('/contract-change-requests', [StudentContractChangeRequestController::class, 'index'])->name('contract-change-requests.index');
        Route::post('/contract-change-requests', [StudentContractChangeRequestController::class, 'store'])->name('contract-change-requests.store');
        Route::get('/personal-information-change-requests', [StudentPersonalInformationChangeRequestController::class, 'index'])->name('personal-information-change-requests.index');
        Route::post('/personal-information-change-requests', [StudentPersonalInformationChangeRequestController::class, 'store'])->name('personal-information-change-requests.store');
        Route::get('/payment-method-change-requests', [StudentPaymentMethodChangeRequestController::class, 'index'])->name('payment-method-change-requests.index');
        Route::post('/payment-method-change-requests', [StudentPaymentMethodChangeRequestController::class, 'store'])->name('payment-method-change-requests.store');
        Route::get('/inquiries', [StudentInquiryController::class, 'index'])->name('inquiries.index');
        Route::post('/inquiries', [StudentInquiryController::class, 'store'])->name('inquiries.store');
        Route::get('/inquiries/{inquiry}', [StudentInquiryController::class, 'show'])->name('inquiries.show');
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
        Route::get('/procedure-requests', ProcedureRequestController::class)->name('procedure-requests.index');
        Route::get('/contract-change-requests/{contract_change_request}', [StaffContractChangeRequestController::class, 'show'])->name('contract-change-requests.show');
        Route::patch('/contract-change-requests/{contract_change_request}', [StaffContractChangeRequestController::class, 'update'])->name('contract-change-requests.update');
        Route::get('/personal-information-change-requests/{personal_change}', [StaffPersonalInformationChangeRequestController::class, 'show'])->name('personal-information-change-requests.show');
        Route::patch('/personal-information-change-requests/{personal_change}', [StaffPersonalInformationChangeRequestController::class, 'update'])->name('personal-information-change-requests.update');
        Route::get('/payment-method-change-requests/{payment_change}', [StaffPaymentMethodChangeRequestController::class, 'show'])->name('payment-method-change-requests.show');
        Route::patch('/payment-method-change-requests/{payment_change}', [StaffPaymentMethodChangeRequestController::class, 'update'])->name('payment-method-change-requests.update');
        Route::get('/inquiries', [StaffInquiryController::class, 'index'])->name('inquiries.index');
        Route::get('/inquiries/{inquiry}', [StaffInquiryController::class, 'show'])->name('inquiries.show');
        Route::patch('/inquiries/{inquiry}', [StaffInquiryController::class, 'update'])->name('inquiries.update');
        Route::resource('students', StaffStudentController::class)->only(['index', 'show']);
        Route::get('/pricing-settings', [PricingSettingController::class, 'index'])->name('pricing-settings.index');
        Route::post('/pricing-settings/rates', [PricingSettingController::class, 'storeRate'])->name('pricing-settings.rates.store');
        Route::post('/pricing-settings/configuration', [PricingSettingController::class, 'storeSetting'])->name('pricing-settings.configuration.store');
        Route::get('/trial-lessons', [StaffTrialLessonRequestController::class, 'index'])->name('trial-lessons.index');
        Route::get('/trial-lessons/{trial_lesson_request}', [StaffTrialLessonRequestController::class, 'show'])->name('trial-lessons.show');
        Route::patch('/trial-lessons/{trial_lesson_request}', [StaffTrialLessonRequestController::class, 'update'])->name('trial-lessons.update');
        Route::get('/admission-applications', [StaffAdmissionApplicationController::class, 'index'])->name('admission-applications.index');
        Route::get('/admission-applications/{admission_application}', [StaffAdmissionApplicationController::class, 'show'])->name('admission-applications.show');
        Route::patch('/admission-applications/{admission_application}', [StaffAdmissionApplicationController::class, 'update'])->name('admission-applications.update');
    });
});
