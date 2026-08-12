<?php

use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AiController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\ClinicalController;
use App\Http\Controllers\Api\V1\CounselorController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\FrontDeskController;
use App\Http\Controllers\Api\V1\FrontDeskExtrasController;
use App\Http\Controllers\Api\V1\IntakeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\SaaSController;
use App\Http\Controllers\Api\V1\VitalNurseController;
use App\Http\Middleware\EnsureSessionAwake;
use App\Http\Middleware\ForceJsonResponse;
use App\Support\Roles;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware([ForceJsonResponse::class])->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
    Route::post('auth/accept-invitation', [AuthController::class, 'acceptInvitation'])->middleware('throttle:10,1');

    Route::middleware('throttle:30,1')->prefix('intake')->group(function () {
        Route::get('{token}', [IntakeController::class, 'show']);
        Route::post('{token}', [IntakeController::class, 'submit']);
    });

    Route::middleware(['auth:sanctum', 'clinic.active', EnsureSessionAwake::class])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/logout-all', [AuthController::class, 'logoutAll']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/sleep', [AuthController::class, 'sleep']);
        Route::post('auth/wake', [AuthController::class, 'wake'])->middleware('throttle:10,1');

        Route::post('files', [FileController::class, 'upload'])
            ->middleware('role:'.Roles::FRONT_DESK.','.Roles::CLINIC_ADMIN.','.Roles::DOCTOR.','.Roles::NP.','.Roles::VITAL_NURSE.','.Roles::COUNSELOR.','.Roles::BILLING);
        Route::post('files/{document}/signed-url', [FileController::class, 'signedUrl']);
        Route::get('files/{document}/download', [FileController::class, 'download'])
            ->name('api.v1.files.download')
            ->middleware('signed');

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);

        Route::middleware('role:'.Roles::SUPER_ADMIN)->prefix('saas')->group(function () {
            Route::get('dashboard', [SaaSController::class, 'dashboard']);
            Route::get('plans', [SaaSController::class, 'plans']);
            Route::post('plans', [SaaSController::class, 'storePlan']);
            Route::get('clinics', [SaaSController::class, 'clinics']);
            Route::post('clinics', [SaaSController::class, 'storeClinic']);
            Route::patch('clinics/{clinic}', [SaaSController::class, 'updateClinic']);
            Route::patch('clinics/{clinic}/status', [SaaSController::class, 'updateClinicStatus']);
            Route::get('clinics/{clinic}/usage', [SaaSController::class, 'usage']);
            Route::post('clinics/{clinic}/credits', [SaaSController::class, 'allocateCredits']);
            Route::post('clinics/{clinic}/stripe-sandbox', [SaaSController::class, 'attachStripeSandbox']);
        });

        Route::middleware('role:'.Roles::CLINIC_ADMIN.','.Roles::DOCTOR.','.Roles::NP.','.Roles::VITAL_NURSE.','.Roles::FRONT_DESK.','.Roles::COUNSELOR.','.Roles::BILLING)
            ->group(function () {
                Route::get('patients', [PatientController::class, 'index']);
                Route::get('patients/{patient}', [PatientController::class, 'show'])->middleware('patient.access');
            });

        Route::post('patients', [PatientController::class, 'store'])
            ->middleware('role:'.Roles::FRONT_DESK.','.Roles::CLINIC_ADMIN);

        Route::patch('patients/{patient}', [PatientController::class, 'update'])
            ->middleware(['role:'.Roles::FRONT_DESK.','.Roles::CLINIC_ADMIN, 'patient.access']);

        Route::middleware('role:'.Roles::FRONT_DESK.','.Roles::CLINIC_ADMIN)->prefix('front-desk')->group(function () {
            Route::get('dashboard', [FrontDeskController::class, 'dashboard']);
            Route::get('queue', [FrontDeskController::class, 'todayQueue']);
            Route::get('appointments', [FrontDeskController::class, 'appointments']);
            Route::post('appointments', [FrontDeskController::class, 'schedule']);
            Route::patch('appointments/{appointment}', [FrontDeskController::class, 'updateAppointment']);
            Route::post('appointments/{appointment}/rebook', [FrontDeskController::class, 'rebook']);
            Route::post('appointments/{appointment}/check-in', [FrontDeskController::class, 'checkIn']);
            Route::post('appointments/{appointment}/cancel', [FrontDeskController::class, 'cancel']);
            Route::post('appointments/{appointment}/no-show', [FrontDeskController::class, 'markNoShow']);
            Route::get('providers', [FrontDeskController::class, 'providers']);
            Route::get('payments', [FrontDeskController::class, 'payments']);
            Route::post('payments', [FrontDeskController::class, 'collectPayment']);
            Route::get('payments/{payment}', [FrontDeskController::class, 'receipt']);
            Route::post('payments/{payment}/refund', [FrontDeskController::class, 'refundPayment']);
            Route::get('patients/{patient}/ledger', [FrontDeskController::class, 'patientLedger']);

            Route::get('messages/templates', [FrontDeskExtrasController::class, 'messageTemplates']);
            Route::get('messages', [FrontDeskExtrasController::class, 'messageHistory']);
            Route::post('messages', [FrontDeskExtrasController::class, 'sendMessage'])->middleware('throttle:20,1');
            Route::post('messages/mass', [FrontDeskExtrasController::class, 'massMessage'])->middleware('throttle:10,1');
            Route::post('phone-notes', [FrontDeskExtrasController::class, 'phoneNote']);
            Route::post('insurance-verify', [FrontDeskExtrasController::class, 'insuranceVerify']);
            Route::post('voice-command', [FrontDeskExtrasController::class, 'voiceCommand']);
            Route::post('intake-sessions', [FrontDeskExtrasController::class, 'createIntakeSession']);
            Route::get('intake-sessions', [FrontDeskExtrasController::class, 'intakeSessions']);
        });

        Route::middleware('role:'.Roles::VITAL_NURSE)->prefix('vitals')->group(function () {
            Route::get('dashboard', [VitalNurseController::class, 'dashboard']);
            Route::get('queue', [VitalNurseController::class, 'queue']);
            Route::get('patients/{patient}/overview', [VitalNurseController::class, 'patientOverview'])->middleware('patient.access');
            Route::get('patients/{patient}/history', [VitalNurseController::class, 'history'])->middleware('patient.access');
            Route::post('/', [VitalNurseController::class, 'storeVitals']);
            Route::post('appointments/{appointment}/start', [VitalNurseController::class, 'startVitals']);
            Route::post('appointments/{appointment}/complete', [VitalNurseController::class, 'completeVitals']);
        });

        Route::middleware('role:'.Roles::DOCTOR.','.Roles::NP)->prefix('clinical')->group(function () {
            Route::get('dashboard', [ClinicalController::class, 'dashboard']);
            Route::get('schedule', [ClinicalController::class, 'schedule']);
            Route::get('analytics', [ClinicalController::class, 'analytics']);
            Route::post('appointments/{appointment}/start', [ClinicalController::class, 'startVisit']);
            Route::post('appointments/{appointment}/complete', [ClinicalController::class, 'completeVisit']);
            Route::patch('lab-orders/{labOrder}/result', [ClinicalController::class, 'updateLabResult']);
            Route::post('billing-codes/{billingCode}/confirm', [ClinicalController::class, 'confirmBillingCode']);

            Route::get('patients/{patient}/summary', [ClinicalController::class, 'summary'])->middleware('patient.access');
            Route::get('patients/{patient}/chart', [ClinicalController::class, 'chart'])->middleware('patient.access');
            Route::post('patients/{patient}/notes', [ClinicalController::class, 'storeNote'])->middleware('patient.access');
            Route::post('patients/{patient}/notes/{note}/sign', [ClinicalController::class, 'signNote'])->middleware('patient.access');
            Route::post('patients/{patient}/diagnoses', [ClinicalController::class, 'storeDiagnosis'])->middleware('patient.access');
            Route::post('patients/{patient}/prescriptions', [ClinicalController::class, 'storePrescription'])->middleware('patient.access');
            Route::post('patients/{patient}/lab-orders', [ClinicalController::class, 'storeLabOrder'])->middleware('patient.access');
            Route::post('patients/{patient}/treatment-plans', [ClinicalController::class, 'storeTreatmentPlan'])->middleware('patient.access');
            Route::post('patients/{patient}/follow-ups', [ClinicalController::class, 'storeFollowUp'])->middleware('patient.access');
        });

        Route::middleware('role:'.Roles::COUNSELOR)->prefix('counselor')->group(function () {
            Route::get('dashboard', [CounselorController::class, 'dashboard']);
            Route::get('schedule', [CounselorController::class, 'schedule']);
            Route::post('appointments/{appointment}/complete', [CounselorController::class, 'completeSession']);
            Route::get('patients/{patient}/doctor-diagnosis', [CounselorController::class, 'doctorDiagnosis'])->middleware('patient.access');
            Route::get('patients/{patient}/sessions', [CounselorController::class, 'sessions'])->middleware('patient.access');
            Route::post('patients/{patient}/sessions', [CounselorController::class, 'storeSession'])->middleware('patient.access');
            Route::patch('patients/{patient}/sessions/{session}/goals', [CounselorController::class, 'updateGoals'])->middleware('patient.access');
            Route::get('patients/{patient}/assessments', [CounselorController::class, 'assessments'])->middleware('patient.access');
            Route::post('patients/{patient}/assessments', [CounselorController::class, 'storeAssessment'])->middleware('patient.access');
            Route::get('patients/{patient}/billing-codes', [CounselorController::class, 'billingSuggestions'])->middleware('patient.access');
            Route::post('billing-codes/{billingCode}/confirm', [CounselorController::class, 'confirmBillingCode']);
        });

        Route::middleware('role:'.Roles::BILLING.','.Roles::CLINIC_ADMIN)->prefix('billing')->group(function () {
            Route::get('dashboard', [BillingController::class, 'dashboard']);
            Route::get('ledger', [BillingController::class, 'ledger']);
            Route::get('codes/pending', [BillingController::class, 'pendingCodes']);
            Route::post('codes/suggest', [BillingController::class, 'suggestCodes']);
            Route::post('codes/{billingCode}/confirm', [BillingController::class, 'confirmCode']);
            Route::post('claims', [BillingController::class, 'createClaim']);
            Route::post('eligibility', [BillingController::class, 'eligibility']);
            Route::get('expenses', [BillingController::class, 'expenses']);
            Route::post('expenses', [BillingController::class, 'storeExpense']);
            Route::get('payments', [BillingController::class, 'payments']);
            Route::get('insurances', [BillingController::class, 'insurances']);
        });

        Route::post('ai/monk', [AiController::class, 'command'])
            ->middleware('role:'.Roles::DOCTOR.','.Roles::NP.','.Roles::VITAL_NURSE.','.Roles::FRONT_DESK.','.Roles::COUNSELOR.','.Roles::CLINIC_ADMIN);

        Route::middleware('role:'.Roles::CLINIC_ADMIN)->prefix('admin')->group(function () {
            Route::get('dashboard', [AdminController::class, 'dashboard']);
            Route::get('users', [AdminController::class, 'users']);
            Route::patch('users/{user}', [AdminController::class, 'updateUser']);
            Route::get('invitations', [AdminController::class, 'invitations']);
            Route::post('invitations', [AdminController::class, 'invite']);
            Route::get('roles', [AdminController::class, 'roles']);
            Route::get('oversight', [AdminController::class, 'oversight']);
            Route::get('settings', [AdminController::class, 'settings']);
            Route::patch('settings', [AdminController::class, 'updateSettings']);
            Route::get('operational-suggestions', [AdminController::class, 'operationalSuggestions']);
        });

        Route::middleware('role:'.Roles::CLINIC_ADMIN.','.Roles::SUPER_ADMIN.','.Roles::BILLING)
            ->get('admin/audit-logs', [AdminController::class, 'auditLogs']);
    });
});
