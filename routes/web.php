<?php

use App\Http\Controllers\StaffAdventurerController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use App\Http\Controllers\ClubController;
use App\Http\Controllers\ClubCarpetaClassActivationController;
use App\Http\Controllers\MemberAdventurerController;
use App\Http\Controllers\ParentAuthController;
use App\Http\Controllers\ParentCarpetaController;
use App\Http\Controllers\PublicMemberEvidenceController;
use App\Models\Club;
use App\Models\AiRequestLog;
use App\Models\Church;
use App\Models\User;
use App\Http\Controllers\ChurchController;
use App\Http\Controllers\ParentMemberController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ClubClassController;
use App\Http\Controllers\LLMQueryController as AIQueryController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\AssistanceReportController;
use App\Http\Controllers\ClubPaymentController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\AccountingCorrectionController;
use App\Http\Controllers\RepAssistanceAdvController;
use App\Models\SubRole;
use App\Http\Controllers\ReportController;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Controllers\WorkplanController;
use App\Http\Controllers\ClubSettingsController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventPlanController;
use App\Http\Controllers\EventTaskController;
use App\Http\Controllers\EventBudgetItemController;
use App\Http\Controllers\EventParticipantController;
use App\Http\Controllers\EventDocumentController;
use App\Http\Controllers\EventDriverController;
use App\Http\Controllers\EventVehicleController;
use App\Http\Controllers\EventPlannerController;
use App\Http\Controllers\EventPlaceOptionController;
use App\Http\Controllers\TaskFormController;
use App\Http\Controllers\ClassInvestitureRequirementController;
use App\Http\Controllers\ClubPersonalInvestitureProgressController;
use App\Http\Controllers\SuperAdminContextController;
use App\Http\Controllers\PaymentReceiptController;
use App\Http\Controllers\UnionController;
use App\Http\Controllers\UnionWorkplanController;
use App\Http\Controllers\AssociationController;
use App\Http\Controllers\DistrictController;
use App\Http\Controllers\DocumentValidationController;
use App\Http\Controllers\InvestitureRequestController;
use App\Models\Association;
use App\Models\District;
use App\Models\Union;
use App\Support\ClubHelper;
use App\Support\SuperadminContext;

// ---------------------------------
// 🔗 Public Routes
// ---------------------------------

Route::get('/', function () {
    if (Auth::check()) {
        return redirect(RedirectIfAuthenticated::redirectPath());
    }

    return Inertia::render('Landing');
});
Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    return redirect('/');
})->name('logout');

Route::get('/force-logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    return redirect('/login');
});

Route::get('/documents/validate/{checksum}', [DocumentValidationController::class, 'show'])
    ->name('documents.validate');
Route::get('/carpeta-investidura/validate/{checksum}', [DocumentValidationController::class, 'show'])
    ->name('carpeta-investidura.validate');
Route::get('/evidence/{code}', [PublicMemberEvidenceController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('public.member-evidence.show');
Route::get('/evidence/{code}/pdf', [PublicMemberEvidenceController::class, 'pdf'])
    ->middleware('throttle:12,1')
    ->name('public.member-evidence.pdf');
Route::post('/evidence/{code}', [PublicMemberEvidenceController::class, 'storeEvidence'])
    ->middleware('throttle:12,1')
    ->name('public.member-evidence.store');

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', fn() => Inertia::render('Dashboard', [
        'auth_user' => auth()->user(),
    ]))->name('dashboard');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('/assistance-reports/filter', [ReportController::class, 'assistanceReportsDirector'])->name('assistance-reports.director');
    Route::get('/financial-report/bootstrap', [ReportController::class, 'financialReportPreload'])->name('financial.preload');
    Route::get('/financial-report/report', [ReportController::class, 'financialReport'])->name('financial.report');
    Route::get('/financial-report/pdf', [ReportController::class, 'financialReportPdf'])->name('financial.report.pdf');
    Route::get('/financial-report/accounts', [ReportController::class, 'financialAccountBalances'])->name('financial.accounts');
    Route::get('/financial-report/accounts/pdf', [ReportController::class, 'financialAccountBalancesPdf'])->name('financial.accounts.pdf');
});

Route::middleware(['auth', 'verified', 'profile:district_pastor,district_secretary'])->group(function () {
    Route::get('/district/dashboard', fn() => Inertia::render('District/Dashboard', [
        'auth_user' => auth()->user(),
    ]))->name('district.dashboard');
    Route::get('/district/workplan', [DistrictController::class, 'workplan'])->name('district.workplan');
    Route::post('/district/workplan/publish', [DistrictController::class, 'publishWorkplan'])->name('district.workplan.publish');
    Route::post('/district/workplan/unpublish', [DistrictController::class, 'unpublishWorkplan'])->name('district.workplan.unpublish');
    Route::post('/district/workplan/sync-missing', [DistrictController::class, 'syncWorkplanMissing'])->name('district.workplan.sync-missing');
    Route::post('/district/workplan/events', [DistrictController::class, 'storeWorkplanEvent'])->name('district.workplan.events.store');
    Route::put('/district/workplan/events/{event}', [DistrictController::class, 'updateWorkplanEvent'])->name('district.workplan.events.update');
    Route::delete('/district/workplan/events/{event}', [DistrictController::class, 'destroyWorkplanEvent'])->name('district.workplan.events.destroy');
    Route::get('/district/investiture-requests', [InvestitureRequestController::class, 'districtIndex'])->name('district.investiture-requests');
    Route::get('/district/investiture-requests/{investitureRequest}', [InvestitureRequestController::class, 'districtShow'])->name('district.investiture-requests.show');
    Route::patch('/district/investiture-requests/{investitureRequest}/reviews/{review}', [InvestitureRequestController::class, 'updateRequirementReview'])
        ->name('district.investiture-requests.reviews.update');

    Route::get('/district/reports/assistance', function () {
        return Inertia::render('ClubDirector/Reports/Assistance', [
            'auth_user' => auth()->user(),
            'sub_roles' => SubRole::all(),
        ]);
    })->name('district.reports.assistance');

    Route::get('/district/reports/finances', function () {
        return Inertia::render('ClubDirector/Reports/Finances', [
            'auth_user' => auth()->user(),
            'sub_roles' => SubRole::all(),
        ]);
    })->name('district.reports.finances');
});

Route::middleware(['auth', 'verified', 'profile:association_youth_director'])->group(function () {
    Route::get('/association/dashboard', fn() => Inertia::render('Association/Dashboard', [
        'auth_user' => auth()->user(),
    ]))->name('association.dashboard');
    Route::get('/association/workplan', [AssociationController::class, 'workplan'])->name('association.workplan');
    Route::post('/association/workplan/publish', [AssociationController::class, 'publishWorkplan'])->name('association.workplan.publish');
    Route::post('/association/workplan/unpublish', [AssociationController::class, 'unpublishWorkplan'])->name('association.workplan.unpublish');
    Route::post('/association/workplan/sync-missing', [AssociationController::class, 'syncWorkplanMissing'])->name('association.workplan.sync-missing');
    Route::post('/association/workplan/events', [AssociationController::class, 'storeWorkplanEvent'])->name('association.workplan.events.store');
    Route::put('/association/workplan/events/{event}', [AssociationController::class, 'updateWorkplanEvent'])->name('association.workplan.events.update');
    Route::delete('/association/workplan/events/{event}', [AssociationController::class, 'destroyWorkplanEvent'])->name('association.workplan.events.destroy');
    Route::get('/association/programs', [AssociationController::class, 'programs'])->name('association.programs');
    Route::get('/association/districts', [AssociationController::class, 'districtEvaluation'])->name('association.districts');
    Route::post('/association/districts', [AssociationController::class, 'storeDistrict'])->name('association.districts.store');
    Route::patch('/association/districts/{district}', [AssociationController::class, 'updateDistrict'])->name('association.districts.update');
    Route::post('/association/evaluators', [AssociationController::class, 'storeEvaluator'])->name('association.evaluators.store');
    Route::delete('/association/evaluators/{evaluator}', [AssociationController::class, 'destroyEvaluator'])->name('association.evaluators.destroy');
    Route::get('/association/clubs', [AssociationController::class, 'associationClubs'])->name('association.clubs');
    Route::post('/association/clubs', [AssociationController::class, 'storeAssociationClub'])->name('association.clubs.store');
    Route::post('/association/clubs/{club}/director', [AssociationController::class, 'storeClubDirector'])->name('association.clubs.director.store');
    Route::patch('/association/clubs/{club}/members/{member}/insurance', [AssociationController::class, 'toggleMemberInsurance'])->name('association.clubs.members.insurance');
    Route::get('/association/settings', [AssociationController::class, 'associationSettings'])->name('association.settings');
    Route::patch('/association/settings', [AssociationController::class, 'updateAssociationSettings'])->name('association.settings.update');
    Route::get('/association/churches', [AssociationController::class, 'churches'])->name('association.churches');
    Route::post('/association/churches', [AssociationController::class, 'storeChurch'])->name('association.churches.store');
    Route::patch('/association/churches/{church}', [AssociationController::class, 'updateChurch'])->name('association.churches.update');
    Route::delete('/association/churches/{church}', [AssociationController::class, 'destroyChurch'])->name('association.churches.destroy');
    Route::post('/association/programs/honor-sessions', [AssociationController::class, 'storeHonorSession'])->name('association.programs.honor-sessions.store');
    Route::delete('/association/programs/honor-sessions/{session}', [AssociationController::class, 'destroyHonorSession'])->name('association.programs.honor-sessions.destroy');
    Route::get('/association/investiture-requests', [InvestitureRequestController::class, 'associationIndex'])->name('association.investiture-requests');
    Route::post('/association/investiture-requests/{investitureRequest}/assign-district-pastor', [InvestitureRequestController::class, 'assignDistrictPastor'])
        ->name('association.investiture-requests.assign-district-pastor');
    Route::post('/association/investiture-requests/{investitureRequest}/authorize', [InvestitureRequestController::class, 'authorizeInvestiture'])
        ->name('association.investiture-requests.authorize');
    Route::post('/association/investiture-requests/{investitureRequest}/request-new-date', [InvestitureRequestController::class, 'requestNewDate'])
        ->name('association.investiture-requests.request-new-date');

    Route::get('/association/reports/assistance', function () {
        return Inertia::render('ClubDirector/Reports/Assistance', [
            'auth_user' => auth()->user(),
            'sub_roles' => SubRole::all(),
        ]);
    })->name('association.reports.assistance');

    Route::get('/association/reports/finances', function () {
        return Inertia::render('ClubDirector/Reports/Finances', [
            'auth_user' => auth()->user(),
            'sub_roles' => SubRole::all(),
        ]);
    })->name('association.reports.finances');
});

Route::middleware(['auth', 'verified', 'profile:union_youth_director'])->group(function () {
    Route::get('/union/dashboard', fn() => Inertia::render('Union/Dashboard', [
        'auth_user' => auth()->user(),
    ]))->name('union.dashboard');
    Route::get('/union/catalog/clubs-classes', [UnionController::class, 'catalog'])->name('union.catalog');
    Route::post('/union/catalog/clubs-classes/club-types', [UnionController::class, 'storeClubCatalog'])->name('union.catalog.club-types.store');
    Route::post('/union/catalog/clubs-classes/club-types/{clubCatalog}/classes', [UnionController::class, 'storeClassCatalog'])->name('union.catalog.classes.store');
    Route::get('/union/carpeta-builder', [UnionController::class, 'builder'])->name('union.carpeta-builder');
    Route::put('/union/carpeta-builder/evaluation-system', [UnionController::class, 'updateScopedEvaluationSystem'])
        ->name('union.carpeta-builder.evaluation-system.update');
    Route::post('/union/carpeta-builder/years', [UnionController::class, 'storeCarpetaYear'])
        ->name('union.carpeta-builder.years.store');
    Route::post('/union/carpeta-builder/years/{carpetaYear}/requirements', [UnionController::class, 'storeCarpetaRequirement'])
        ->name('union.carpeta-builder.requirements.store');
    Route::delete('/union/carpeta-builder/requirements/{requirement}', [UnionController::class, 'destroyCarpetaRequirement'])
        ->name('union.carpeta-builder.requirements.destroy');
    Route::put('/union/carpeta-builder/years/{carpetaYear}/publish', [UnionController::class, 'publishCarpetaYear'])
        ->name('union.carpeta-builder.years.publish');
    Route::put('/union/carpeta-builder/years/{carpetaYear}/archive', [UnionController::class, 'archiveCarpetaYear'])
        ->name('union.carpeta-builder.years.archive');

    Route::get('/union/reports/assistance', [UnionController::class, 'attendanceReport'])->name('union.reports.assistance');
    Route::get('/union/reports/assistance/csv', [UnionController::class, 'attendanceReportCsv'])->name('union.reports.assistance.csv');

    Route::get('/union/workplan', [UnionWorkplanController::class, 'index'])->name('union.workplan');
    Route::get('/union/workplan/pdf', [UnionWorkplanController::class, 'pdf'])->name('union.workplan.pdf');
    Route::post('/union/workplan/publish', [UnionWorkplanController::class, 'publish'])->name('union.workplan.publish');
    Route::post('/union/workplan/unpublish', [UnionWorkplanController::class, 'unpublish'])->name('union.workplan.unpublish');
    Route::post('/union/workplan/sync-missing', [UnionWorkplanController::class, 'syncMissing'])->name('union.workplan.sync-missing');
    Route::post('/union/workplan/events', [UnionWorkplanController::class, 'store'])->name('union.workplan.events.store');
    Route::put('/union/workplan/events/{event}', [UnionWorkplanController::class, 'update'])->name('union.workplan.events.update');
    Route::delete('/union/workplan/events/{event}', [UnionWorkplanController::class, 'destroy'])->name('union.workplan.events.destroy');

    Route::get('/union/reports/progress', [UnionController::class, 'progressReport'])->name('union.reports.progress');
    Route::get('/union/reports/progress/csv', [UnionController::class, 'progressReportCsv'])->name('union.reports.progress.csv');
});

// ---------------------------------
// 🔐 Guest Routes (Parent Self-Registration)
// ---------------------------------
Route::middleware(['guest'])->group(function () {
Route::post('/setup/superadmin', [RegisteredUserController::class, 'storeSuperadmin'])->name('superadmin.setup.store');
Route::get('/register-parent', [ParentAuthController::class, 'showRegistrationForm'])->name('parent.register');
Route::post('/register-parent/resolve-invite', [ParentAuthController::class, 'resolveInvite'])->name('parent.register.resolve-invite');
Route::post('/register-parent', [ParentAuthController::class, 'register']);
Route::get('/churches/{church}/clubs', [ClubController::class, 'getByChurch']);
});

Route::middleware(['auth', 'verified', 'profile:club_personal'])->group(function () {
    Route::get('/club-personal/workplan', [WorkplanController::class, 'index'])->name('club.personal.workplan');
    Route::get('/club-personal/workplan/pdf', [WorkplanController::class, 'pdf'])->name('club.personal.workplan.pdf');
    Route::get('/club-personal/workplan/data', [WorkplanController::class, 'data'])->name('club.personal.workplan.data');
    Route::get('/club-personal/workplan/ics', [WorkplanController::class, 'ics'])->name('club.personal.workplan.ics');
    Route::get('/club-personal/workplan/class-plans/pdf', [WorkplanController::class, 'classPlansPdf'])->name('club.personal.workplan.class-plans.pdf');
    Route::get('/club-personal/investiture-requirements', [ClubPersonalInvestitureProgressController::class, 'index'])
        ->name('club.personal.investiture-requirements');
    Route::get('/club-personal/investiture-requirements/pdf', [ClubPersonalInvestitureProgressController::class, 'pdf'])
        ->name('club.personal.investiture-requirements.pdf');
    Route::post('/club-personal/investiture-requirements/completions', [ClubPersonalInvestitureProgressController::class, 'storeCompletion'])
        ->name('club.personal.investiture-requirements.completions.store');
    Route::post('/club-personal/class-plans', [\App\Http\Controllers\ClassPlanController::class, 'store'])->name('club.personal.class-plans.store');
    Route::put('/club-personal/class-plans/{plan}', [\App\Http\Controllers\ClassPlanController::class, 'update'])->name('club.personal.class-plans.update');
    Route::delete('/club-personal/class-plans/{plan}', [\App\Http\Controllers\ClassPlanController::class, 'destroy'])->name('club.personal.class-plans.destroy');
    Route::get('/club-personal/receipts', [PaymentReceiptController::class, 'staffIndex'])->name('club.personal.receipts.index');
});

// ---------------------------------
// 🟣 Parent-Only Routes (Authenticated)
// ---------------------------------
Route::middleware(['auth', 'verified', 'auth.parent'])->group(function () {
    Route::get('/parent/dashboard', fn() => Inertia::render('Parent/Dashboard', [
        'auth_user' => auth()->user(),
    ]))->name('parent.dashboard');

    Route::get('/parent/apply', fn() => Inertia::render('Parent/Apply', [
        'auth_user' => auth()->user(),
        'clubs' => Club::all(),
    ]))->name('parent.apply');

    Route::post('/parent/apply', [MemberAdventurerController::class, 'store'])->name('parent.apply.submit');
    Route::get('/parent/children', [ParentMemberController::class, 'index'])->name('parent-links.index.parent');
    Route::put('/parent/children/{id}', [ParentMemberController::class, 'update'])->name('parent.children.update');
    Route::get('/parent/children/linkable', [ParentMemberController::class, 'linkable'])->name('parent.children.linkable');
    Route::post('/parent/children/link', [ParentMemberController::class, 'link'])->name('parent.children.link');
    Route::get('/parent/carpeta-investidura', [ParentCarpetaController::class, 'index'])->name('parent.carpeta-investidura');
    Route::get('/parent/carpeta-investidura/{member}/pdf', [ParentCarpetaController::class, 'pdf'])->name('parent.carpeta-investidura.pdf');
    Route::post('/parent/carpeta-investidura/evidence', [ParentCarpetaController::class, 'storeEvidence'])->name('parent.carpeta-investidura.evidence.store');

    Route::get('/parent/workplan/data', [WorkplanController::class, 'data'])->name('parent.workplan.data');
    Route::get('/parent/workplan/pdf', [WorkplanController::class, 'pdf'])->name('parent.workplan.pdf');
    Route::get('/parent/workplan/ics', [WorkplanController::class, 'ics'])->name('parent.workplan.ics');
    Route::get('/parent/workplan/class-plans/pdf', [WorkplanController::class, 'classPlansPdf'])->name('parent.workplan.class-plans.pdf');
    Route::get('/parent/receipts', [PaymentReceiptController::class, 'parentIndex'])->name('parent.receipts.index');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/payment-receipts/{receipt}/download', [PaymentReceiptController::class, 'download'])->name('payment-receipts.download');
    Route::post('/payment-receipts/download-bulk', [PaymentReceiptController::class, 'downloadBulk'])->name('payment-receipts.download-bulk');
});

// ---------------------------------
// 🟥 Superadmin Protected Routes
// ---------------------------------
Route::middleware(['auth', 'verified', 'profile:superadmin'])->group(function () {
    Route::get('/super-admin/dashboard', function () {
        $context = SuperadminContext::fromSession();

        return Inertia::render('SuperAdmin/Dashboard', [
            'auth_user' => auth()->user(),
            'unions' => Union::query()
                ->where('status', '!=', 'deleted')
                ->orderBy('name')
                ->get(['id', 'name']),
            'associations' => Association::query()
                ->where('status', '!=', 'deleted')
                ->orderBy('name')
                ->get(['id', 'union_id', 'name']),
            'districts' => District::query()
                ->where('status', '!=', 'deleted')
                ->orderBy('name')
                ->get(['id', 'association_id', 'name']),
            'churches' => Church::query()
                ->orderBy('church_name')
                ->get(['id', 'district_id', 'church_name']),
            'clubs' => Club::query()
                ->withoutGlobalScopes()
                ->select('id', 'club_name', 'church_id')
                ->orderBy('club_name')
                ->get(),
            'context' => $context,
        ]);
    })->name('superadmin.dashboard');
    Route::post('/super-admin/context', [SuperAdminContextController::class, 'set'])
        ->name('superadmin.context.set');
    Route::get('/super-admin/ai-logs', [\App\Http\Controllers\SuperAdminAiLogController::class, 'index'])
        ->name('superadmin.ai-logs.index');
    Route::get('/super-admin/churches/manage', [ChurchController::class, 'manage'])->name('superadmin.churches.manage');
    Route::get('/super-admin/unions', [UnionController::class, 'index'])->name('superadmin.unions.manage');
    Route::post('/super-admin/unions', [UnionController::class, 'store'])->name('superadmin.unions.store');
    Route::put('/super-admin/unions/{union}', [UnionController::class, 'update'])->name('superadmin.unions.update');
    Route::put('/super-admin/unions/{union}/deactivate', [UnionController::class, 'deactivate'])->name('superadmin.unions.deactivate');
    Route::delete('/super-admin/unions/{union}', [UnionController::class, 'destroy'])->name('superadmin.unions.delete');
    Route::get('/super-admin/associations', [AssociationController::class, 'index'])->name('superadmin.associations.manage');
    Route::post('/super-admin/associations', [AssociationController::class, 'store'])->name('superadmin.associations.store');
    Route::put('/super-admin/associations/{association}', [AssociationController::class, 'update'])->name('superadmin.associations.update');
    Route::put('/super-admin/associations/{association}/deactivate', [AssociationController::class, 'deactivate'])->name('superadmin.associations.deactivate');
    Route::delete('/super-admin/associations/{association}', [AssociationController::class, 'destroy'])->name('superadmin.associations.delete');
    Route::get('/super-admin/districts', [DistrictController::class, 'index'])->name('superadmin.districts.manage');
    Route::post('/super-admin/districts', [DistrictController::class, 'store'])->name('superadmin.districts.store');
    Route::put('/super-admin/districts/{district}', [DistrictController::class, 'update'])->name('superadmin.districts.update');
    Route::put('/super-admin/districts/{district}/deactivate', [DistrictController::class, 'deactivate'])->name('superadmin.districts.deactivate');
    Route::delete('/super-admin/districts/{district}', [DistrictController::class, 'destroy'])->name('superadmin.districts.delete');
    Route::get('/super-admin/clubs', fn() => Inertia::render('SuperAdmin/Clubs', [
        'churches' => Church::query()
            ->with('district.association.union:id,name,evaluation_system')
            ->orderBy('church_name')
            ->get(['id', 'district_id', 'church_name', 'pastor_name', 'conference'])
            ->map(fn ($church) => [
                'id' => $church->id,
                'district_id' => $church->district_id,
                'church_name' => $church->church_name,
                'pastor_name' => $church->pastor_name,
                'conference' => $church->conference,
                'association_name' => $church->district?->association?->name,
                'union_name' => $church->district?->association?->union?->name,
                'evaluation_system' => $church->district?->association?->union?->evaluation_system ?: 'honors',
            ])
            ->values(),
        'directors' => User::select('id', 'name', 'email', 'church_id', 'club_id')
            ->where('profile_type', 'club_director')
            ->where('status', '!=', 'deleted')
            ->orderBy('name')
            ->get(),
        'clubs' => Club::query()
            ->withoutGlobalScopes()
            ->select('id', 'club_name', 'church_name', 'director_name', 'creation_date', 'pastor_name', 'conference_name', 'conference_region', 'club_type', 'evaluation_system', 'church_id', 'district_id', 'user_id', 'status')
            ->orderBy('club_name')
            ->get(),
    ]))->name('superadmin.clubs.manage');
    Route::post('/super-admin/clubs', [ClubController::class, 'storeBySuperadmin'])->name('superadmin.clubs.store');
    Route::put('/super-admin/clubs/{club}', [ClubController::class, 'updateBySuperadmin'])->name('superadmin.clubs.update');
    Route::put('/super-admin/clubs/{club}/deactivate', [ClubController::class, 'deactivateBySuperadmin'])->name('superadmin.clubs.deactivate');
    Route::delete('/super-admin/clubs/{club}', [ClubController::class, 'deleteBySuperadmin'])->name('superadmin.clubs.delete');
    Route::get('/super-admin/users', fn() => Inertia::render('SuperAdmin/Users', [
        'churches' => Church::select('id', 'church_name')->orderBy('church_name')->get(),
        'clubs' => Club::query()
            ->withoutGlobalScopes()
            ->select('id', 'club_name', 'church_id', 'status')
            ->orderBy('club_name')
            ->get(),
        'districts' => \App\Models\District::query()
            ->with('association.union:id,name')
            ->where('status', '!=', 'deleted')
            ->orderBy('name')
            ->get(['id', 'association_id', 'name', 'status']),
        'associations' => \App\Models\Association::query()
            ->with('union:id,name')
            ->where('status', '!=', 'deleted')
            ->orderBy('name')
            ->get(['id', 'union_id', 'name', 'status']),
        'unions' => \App\Models\Union::query()
            ->where('status', '!=', 'deleted')
            ->orderBy('name')
            ->get(['id', 'name', 'status']),
        'subRoles' => SubRole::all(),
        'users' => User::query()
            ->select('id', 'name', 'email', 'profile_type', 'role_key', 'scope_type', 'scope_id', 'sub_role', 'church_id', 'church_name', 'club_id', 'status')
            ->where('status', '!=', 'deleted')
            ->orderBy('name')
            ->get(),
    ]))->name('superadmin.users.manage');
    Route::post('/super-admin/users', [RegisteredUserController::class, 'storeBySuperadmin'])->name('superadmin.users.store');
    Route::put('/super-admin/users/{user}', [RegisteredUserController::class, 'updateBySuperadmin'])->name('superadmin.users.update');
    Route::put('/super-admin/users/{user}/deactivate', [RegisteredUserController::class, 'deactivateBySuperadmin'])->name('superadmin.users.deactivate');
    Route::delete('/super-admin/users/{user}', [RegisteredUserController::class, 'deleteBySuperadmin'])->name('superadmin.users.delete');

    Route::get('/churches', [ChurchController::class, 'index']);
    Route::get('/churches/create', [ChurchController::class, 'manage'])->name('churches.create');
    Route::get('/church-form', [ChurchController::class, 'manage'])->name('church.form');
    Route::post('/churches', [ChurchController::class, 'store']);
    Route::post('/churches/{church}/invite-code', [\App\Http\Controllers\ChurchInviteCodeController::class, 'upsertForChurch'])->name('churches.invite-code');
    Route::put('/churches/{church}', [ChurchController::class, 'update']);
    Route::delete('/churches/{church}', [ChurchController::class, 'destroy']);
    Route::get('/super-admin/churches', [ChurchController::class, 'indexWithInviteCodes'])
        ->name('superadmin.churches.index');
    Route::post('/super-admin/churches/{church}/invite-code', [\App\Http\Controllers\ChurchInviteCodeController::class, 'regenerateForChurch'])
        ->name('superadmin.churches.invite-code');
});

// ---------------------------------
// 🔵 Club Director Protected Routes
// ---------------------------------
Route::middleware(['auth', 'verified', 'profile:club_director'])->group(function () {

    // 🔷 Frontend Views
    Route::get('/director/children', [ParentMemberController::class, 'index'])->name('parent-links.index.director');

    Route::get('/club-director/dashboard', function () {
        $activeClub = ClubHelper::activeClubForUser(Auth::user());
        $club = $activeClub
            ? Club::withoutGlobalScopes()
                ->with([
                    'church.district.association.union',
                    'district.association.union',
                ])
                ->find($activeClub->id)
            : null;

        $district = $club?->district ?: $club?->church?->district;
        $association = $district?->association;
        $union = $association?->union;

        return Inertia::render('ClubDirectorDashboard', [
            'club_hierarchy' => $club ? [
                'club' => [
                    'id' => $club->id,
                    'name' => $club->club_name,
                    'type' => $club->club_type,
                    'status' => $club->status,
                    'evaluation_system' => $club->evaluation_system,
                ],
                'church' => $club->church ? [
                    'id' => $club->church->id,
                    'name' => $club->church->church_name,
                ] : [
                    'id' => $club->church_id,
                    'name' => $club->church_name,
                ],
                'district' => $district ? [
                    'id' => $district->id,
                    'name' => $district->name,
                ] : null,
                'association' => $association ? [
                    'id' => $association->id,
                    'name' => $association->name,
                ] : null,
                'union' => $union ? [
                    'id' => $union->id,
                    'name' => $union->name,
                    'evaluation_system' => $union->evaluation_system,
                ] : null,
            ] : null,
        ]);
    })->name('club.dashboard');

    Route::get(
        '/club-director/my-club',
        function () {
            $user = auth()->user();
            $isSuperadmin = $user?->profile_type === 'superadmin';

            return Inertia::render('ClubDirector/MyClub', [
                'auth_user' => $user,
                'churches' => Church::select('id', 'district_id', 'church_name', 'pastor_name', 'conference')
                    ->orderBy('church_name')
                    ->get(),
                'districts' => \App\Models\District::query()
                    ->with('association.union:id,name,evaluation_system')
                    ->where('status', '!=', 'deleted')
                    ->orderBy('name')
                    ->get(['id', 'association_id', 'name', 'status'])
                    ->map(fn ($district) => [
                        'id' => $district->id,
                        'name' => $district->name,
                        'association_name' => $district->association?->name,
                        'union_name' => $district->association?->union?->name,
                        'evaluation_system' => $district->association?->union?->evaluation_system ?: 'honors',
                    ])
                    ->values(),
                'superadmin_context' => $isSuperadmin ? [
                    'church_id' => session('superadmin_context.church_id')
                        ? (int) session('superadmin_context.church_id')
                        : null,
                    'club_id' => session('superadmin_context.club_id')
                        ? (int) session('superadmin_context.club_id')
                        : null,
                ] : null,
            ]);
        }
    )->name('club.my-club');

    Route::get(
        '/club-director/my-club-finances',
        fn() =>
        Inertia::render('ClubDirector/MyClubFinances', ['auth_user' => auth()->user()])
    )->name('club.my-club-finances');

    Route::get(
        '/club-director/members',
        fn() =>
        Inertia::render('ClubDirector/Members', ['auth_user' => auth()->user()])
    )->name('club.members');

    Route::get('/club-director/payments', [ClubPaymentController::class, 'directorIndex'])
        ->name('club.director.payments');
    Route::post('/club-director/staff/{staff}/approve', [\App\Http\Controllers\StaffApprovalController::class, 'approve'])->name('staff.approve');
    Route::post('/club-director/staff/{staff}/reject', [\App\Http\Controllers\StaffApprovalController::class, 'reject'])->name('staff.reject');
    Route::get('/club-director/expenses', [ExpenseController::class, 'index'])
        ->name('club.director.expenses');
    Route::post('/club-director/expenses', [ExpenseController::class, 'store'])
        ->name('club.director.expenses.store');
    Route::post('/club-director/expenses/{expense}/receipt', [ExpenseController::class, 'uploadReceipt'])
        ->name('club.director.expenses.upload');
    Route::delete('/club-director/expenses/{expense}/receipt', [ExpenseController::class, 'removeReceipt'])
        ->name('club.director.expenses.removeReceipt');
    Route::post('/club-director/expenses/{expense}/reimbursement-receipt', [ExpenseController::class, 'uploadReimbursementReceipt'])
        ->name('club.director.expenses.uploadReimbursementReceipt');
    Route::delete('/club-director/expenses/{expense}/reimbursement-receipt', [ExpenseController::class, 'removeReimbursementReceipt'])
        ->name('club.director.expenses.removeReimbursementReceipt');
    Route::post('/club-director/expenses/{expense}/reimburse', [ExpenseController::class, 'markReimbursed'])
        ->name('club.director.expenses.reimburse');
    Route::get('/club-director/staff', function () {
        $authUser = auth()->user();
        $activeClub = \App\Support\ClubHelper::activeClubForUser($authUser);
        $clubId = $activeClub?->id;

        // Parents who have children in this club (even if parent.club_id differs)
        $parentIdsWithKids = \App\Models\Member::when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->whereNotNull('parent_id')
            ->pluck('parent_id')
            ->unique()
            ->all();

        $parentAccounts = \App\Models\User::with('clubs')
            ->where('profile_type', 'parent')
            ->whereIn('id', $parentIdsWithKids)
            ->get()
            ->map(function ($parent) use ($clubId) {
                $children = \App\Models\Member::with('club')
                    ->where('parent_id', $parent->id)
                    ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
                    ->get()
                    ->map(function ($member) {
                        $detail = \App\Support\ClubHelper::memberDetail($member);
                        return [
                            'id' => $member->id,
                            'member_type' => $member->type,
                            'name' => $detail['name'] ?? null,
                            'class_id' => $member->class_id,
                            'club_id' => $member->club_id,
                            'club_name' => $member->club?->club_name,
                        ];
                    })
                    ->values();

                return [
                    'id' => $parent->id,
                    'name' => $parent->name,
                    'email' => $parent->email,
                    'club_id' => $parent->club_id,
                    'children' => $children,
                ];
            });

        return Inertia::render('ClubDirector/Staff', [
            'auth_user' => $authUser,
            'sub_roles' => SubRole::all(),
            'parent_accounts' => $parentAccounts,
        ]);
    })->name('club.staff');

    Route::get('/club-director/workplan', [WorkplanController::class, 'index'])->name('club.workplan');
    Route::post('/club-director/workplan/preview', [WorkplanController::class, 'preview'])->name('club.workplan.preview');
    Route::post('/club-director/workplan/confirm', [WorkplanController::class, 'confirm'])->name('club.workplan.confirm');
    Route::post('/club-director/workplan/events', [WorkplanController::class, 'storeEvent'])->name('club.workplan.events.store');
    Route::put('/club-director/workplan/events/{event}', [WorkplanController::class, 'updateEvent'])->name('club.workplan.events.update');
    Route::delete('/club-director/workplan/events/{event}', [WorkplanController::class, 'destroyEvent'])->name('club.workplan.events.destroy');
    Route::delete('/club-director/workplan', [WorkplanController::class, 'destroy'])->name('club.workplan.destroy');
    Route::post('/club-director/workplan/export', [WorkplanController::class, 'exportToMyChurchAdmin'])->name('club.workplan.export');
    Route::get('/club-director/workplan/pdf', [WorkplanController::class, 'pdf'])->name('club.workplan.pdf');
    Route::get('/club-director/workplan/table-pdf', [WorkplanController::class, 'tablePdf'])->name('club.workplan.table.pdf');
    Route::get('/club-director/workplan/ics', [WorkplanController::class, 'ics'])->name('club.workplan.ics');
    Route::get('/club-director/workplan/class-plans/pdf', [WorkplanController::class, 'classPlansPdf'])->name('club.workplan.class-plans.pdf');
    Route::put('/club-director/class-plans/{plan}/status', [\App\Http\Controllers\ClassPlanController::class, 'updateStatus'])->name('club.workplan.class-plans.status');
    Route::get('/club-director/church/invite-code', [\App\Http\Controllers\ChurchInviteCodeController::class, 'show'])->name('club.director.church.invite-code');
    Route::post('/club-director/church/invite-code/regenerate', [\App\Http\Controllers\ChurchInviteCodeController::class, 'regenerate'])->name('club.director.church.invite-code.regenerate');
    Route::get('/club-director/settings', [ClubSettingsController::class, 'index'])->name('club.settings');
    Route::post('/club-director/settings/logo', [ClubSettingsController::class, 'uploadLogo'])->name('club.settings.logo');
    Route::delete('/club-director/settings/logo', [ClubSettingsController::class, 'removeLogo'])->name('club.settings.logo.destroy');
    Route::post('/club-director/settings/catalog', [ClubSettingsController::class, 'fetchCatalog'])->name('club.settings.catalog');
    Route::post('/club-director/settings/save', [ClubSettingsController::class, 'saveConfig'])->name('club.settings.save');

    Route::get('/club-director/reports/assistance', function () {
        return Inertia::render('ClubDirector/Reports/Assistance', [
            'auth_user' => auth()->user(),
            'sub_roles' => SubRole::all(),
        ]);
    })->name('club.reports.assistance');

    Route::get('/club-director/reports/finances', function () {
        return Inertia::render('ClubDirector/Reports/Finances', [
            'auth_user' => auth()->user(),
            'sub_roles' => SubRole::all(),
        ]);
    })->name('club.reports.finances');

    Route::get('/club-director/reports/accounts', function () {
        return Inertia::render('ClubDirector/Reports/Accounts', [
            'auth_user' => auth()->user(),
            'sub_roles' => SubRole::all(),
        ]);
    })->name('club.reports.accounts');

    Route::get('/club-director/reports/investiture-requirements', [ReportController::class, 'investitureRequirementsReport'])
        ->name('club.reports.investiture-requirements');
    Route::post('/club-director/reports/investiture-requirements/tentative-date', [InvestitureRequestController::class, 'updateCurrentTentativeDate'])
        ->name('club.reports.investiture-requirements.tentative-date.update-current');
    Route::get('/club-director/reports/investiture-requirements/pdf', [ReportController::class, 'investitureRequirementsReportPdf'])
        ->name('club.reports.investiture-requirements.pdf');
    Route::post('/club-director/reports/investiture-requirements/requests', [InvestitureRequestController::class, 'store'])
        ->name('club.reports.investiture-requirements.requests.store');
    Route::post('/club-director/reports/investiture-requirements/requests/{investitureRequest}/tentative-date', [InvestitureRequestController::class, 'updateTentativeDate'])
        ->name('club.reports.investiture-requirements.requests.tentative-date.update');
    Route::post('/club-director/reports/investiture-requirements/requests/{investitureRequest}/complete-ceremony', [InvestitureRequestController::class, 'completeCeremony'])
        ->name('club.reports.investiture-requirements.requests.complete-ceremony');
    Route::get('/club-director/reports/investiture-requirements/members/{member}/pdf', [ReportController::class, 'carpetaMemberPdf'])
        ->name('club.reports.investiture-requirements.member.pdf');
    Route::post('/club-director/reports/investiture-requirements/members/{member}/access-code', [ReportController::class, 'createCarpetaMemberAccessCode'])
        ->name('club.reports.investiture-requirements.member.access-code.store');
    Route::delete('/club-director/reports/investiture-requirements/members/{member}/access-codes', [ReportController::class, 'revokeCarpetaMemberAccessCodes'])
        ->name('club.reports.investiture-requirements.member.access-codes.revoke');

    // 🟢 API Endpoints

    // Clubs
    Route::get('/clubs/by-ids', [ClubController::class, 'getByIds'])->name('clubs.by-ids');
    Route::get('/clubs/by-user/{user}', [ClubController::class, 'getByUser'])->name('clubs.by-user');
    Route::get('/club', [ClubController::class, 'show']);
    Route::post('/club', [ClubController::class, 'store'])->name('club.store');
    Route::put('/club', [ClubController::class, 'update'])->name('club.update');
    Route::delete('/club', [ClubController::class, 'destroy'])->name('club.destroy');

    Route::resource('club-classes', ClubClassController::class)->names([
        'index' => 'club-classes.index',
        'store' => 'club-classes.store',
        'create' => 'club-classes.create',
        'show' => 'club-classes.show',
        'edit' => 'club-classes.edit',
        'update' => 'club-classes.update',
        'destroy' => 'club-classes.destroy',
    ]);
    Route::post('/club-classes/{clubClass}/investiture-requirements', [ClassInvestitureRequirementController::class, 'store'])
        ->name('investiture-requirements.store');
    Route::put('/investiture-requirements/{investitureRequirement}', [ClassInvestitureRequirementController::class, 'update'])
        ->name('investiture-requirements.update');
    Route::delete('/investiture-requirements/{investitureRequirement}', [ClassInvestitureRequirementController::class, 'destroy'])
        ->name('investiture-requirements.destroy');

    Route::get('/church/{churchId}/clubs', [ClubController::class, 'getClubsByChurchId'])->name('church.clubs');
    Route::post('/club-user', [ClubController::class, 'selectClub'])->name('club.select');
    Route::post('/clubs/{club}/attach-director', [ClubController::class, 'attachDirector'])->name('club.attach-director');
    Route::post('/clubs/{club}/detach-director', [ClubController::class, 'detachDirector'])->name('club.detach-director');
    Route::post('/clubs/{club}/carpeta-class-activations', [ClubCarpetaClassActivationController::class, 'store'])->name('clubs.carpeta-class-activations.store');
    Route::delete('/club-carpeta-class-activations/{activation}', [ClubCarpetaClassActivationController::class, 'destroy'])->name('clubs.carpeta-class-activations.destroy');
    Route::post('/clubs/{club}/objectives', [\App\Http\Controllers\ClubObjectiveController::class, 'store'])->name('clubs.objectives.store');
    Route::put('/clubs/{club}/objectives/{objective}', [\App\Http\Controllers\ClubObjectiveController::class, 'update'])->name('clubs.objectives.update');
    Route::delete('/clubs/{club}/objectives/{objective}', [\App\Http\Controllers\ClubObjectiveController::class, 'destroy'])->name('clubs.objectives.destroy');

    // Members
    Route::post('/members', [MemberAdventurerController::class, 'store'])->name('members.store');
    Route::get('/clubs/{id}/members', [MemberAdventurerController::class, 'byClub'])->name('clubs.members');
    Route::get('/clubs/{id}/members/class-summary-pdf', [MemberAdventurerController::class, 'classSummaryPdf'])->name('clubs.members.class-summary-pdf');
    Route::delete('/members/{id}', [MemberAdventurerController::class, 'destroy'])->name('members.destroy');
    Route::get('/members/{id}/export-word', [MemberAdventurerController::class, 'exportWord'])->name('members.export-word');
    Route::get('/members/{id}/export-pathfinder-pdf', [MemberAdventurerController::class, 'exportPathfinderPdf'])->name('members.export-pathfinder-pdf');
    Route::post('/members/{id}/insurance-card', [MemberAdventurerController::class, 'uploadPathfinderInsuranceCard'])->name('members.pathfinder.insurance-card.upload');
    Route::post('/members/export-zip', [ExportController::class, 'exportZip'])->name('members.export-zip');
    Route::post('/members/class-member-assignments', [MemberAdventurerController::class, 'assignMember'])->name('members.assign');
    Route::post('/members/class-member-assignments/undo', [MemberAdventurerController::class, 'undoLastAssignment'])->name('members.assignment.undo');

    // Staff
    Route::get('/clubs/{clubId}/staff/{churchId?}', [StaffAdventurerController::class, 'byClub'])->name('clubs.staff');
    Route::post('/staff', [StaffAdventurerController::class, 'store'])->name('staff.store');
    Route::post('/staff/create-user', [StaffAdventurerController::class, 'createUser'])->name('staff.createUser');
    Route::post('/staff/{staff}/link-club', [StaffAdventurerController::class, 'linkToClub'])->name('staff.link-club');
    Route::get('/staff/{id}/export-word', [StaffAdventurerController::class, 'exportWord'])->name('staff.export-word');
    Route::post('/staff/update-user-account', [StaffAdventurerController::class, 'updateStaffUserAccount'])->name('staff.updateUserAccount');
    Route::post('/staff/update-staff-account', [StaffAdventurerController::class, 'updateStaffAccount'])->name('staff.updateStaffAccount');
    Route::put('/staff/update-class', [StaffAdventurerController::class, 'updateAssignedClass'])->name('staff.update-class');
    Route::put('/staff/{id}', [StaffAdventurerController::class, 'update'])->name('staff.update');


    // AI
    Route::post('/nl-query', [AIQueryController::class, 'handle']);

    // User approvals
    Route::post('/club-director/users/{user}/approve', [\App\Http\Controllers\UserApprovalController::class, 'approve'])->name('club.users.approve');

    // Export ZIP
    Route::post('/export/{type}/zip', [ExportController::class, 'exportZip'])->name('export.zip');

    // Debug route
    Route::get('/test-template-access', function () {
        $path = storage_path('app/templates/template_adventurer_new.docx');
        return file_exists($path) ? response()->download($path) : 'Template not found.';
    });

});

// ---------------------------------
// 🗓️ Event Planner (Club Director + Club Personal)
// ---------------------------------
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('events', EventController::class);
    Route::get('/events/{event}/pdf', [EventController::class, 'pdf'])->name('events.pdf');
    Route::patch('/event-plans/{event}', [EventPlanController::class, 'update'])->name('event-plans.update');

    Route::get('/events/{event}/tasks', [EventTaskController::class, 'index'])->name('event-tasks.index');
    Route::post('/events/{event}/tasks', [EventTaskController::class, 'store'])->name('event-tasks.store');
    Route::put('/event-tasks/{eventTask}', [EventTaskController::class, 'update'])->name('event-tasks.update');
    Route::delete('/event-tasks/{eventTask}', [EventTaskController::class, 'destroy'])->name('event-tasks.destroy');

    Route::get('/events/{event}/budget-items', [EventBudgetItemController::class, 'index'])->name('event-budget-items.index');
    Route::post('/events/{event}/budget-items', [EventBudgetItemController::class, 'store'])->name('event-budget-items.store');
    Route::put('/event-budget-items/{eventBudgetItem}', [EventBudgetItemController::class, 'update'])->name('event-budget-items.update');
    Route::post('/event-budget-items/{eventBudgetItem}/receipt', [EventBudgetItemController::class, 'uploadReceipt'])->name('event-budget-items.receipt');
    Route::delete('/event-budget-items/{eventBudgetItem}', [EventBudgetItemController::class, 'destroy'])->name('event-budget-items.destroy');

    Route::get('/events/{event}/participants', [EventParticipantController::class, 'index'])->name('event-participants.index');
    Route::post('/events/{event}/participants', [EventParticipantController::class, 'store'])->name('event-participants.store');
    Route::put('/event-participants/{eventParticipant}', [EventParticipantController::class, 'update'])->name('event-participants.update');
    Route::delete('/event-participants/{eventParticipant}', [EventParticipantController::class, 'destroy'])->name('event-participants.destroy');

    Route::get('/events/{event}/documents', [EventDocumentController::class, 'index'])->name('event-documents.index');
    Route::post('/events/{event}/documents', [EventDocumentController::class, 'store'])->name('event-documents.store');
    Route::put('/event-documents/{eventDocument}', [EventDocumentController::class, 'update'])->name('event-documents.update');
    Route::delete('/event-documents/{eventDocument}', [EventDocumentController::class, 'destroy'])->name('event-documents.destroy');

    Route::get('/events/{event}/drivers', [EventDriverController::class, 'index'])->name('event-drivers.index');
    Route::post('/events/{event}/drivers', [EventDriverController::class, 'store'])->name('event-drivers.store');
    Route::put('/event-drivers/{eventDriver}', [EventDriverController::class, 'update'])->name('event-drivers.update');
    Route::delete('/event-drivers/{eventDriver}', [EventDriverController::class, 'destroy'])->name('event-drivers.destroy');
    Route::post('/event-drivers/{eventDriver}/vehicles', [EventVehicleController::class, 'store'])->name('event-vehicles.store');
    Route::put('/event-vehicles/{eventVehicle}', [EventVehicleController::class, 'update'])->name('event-vehicles.update');
    Route::delete('/event-vehicles/{eventVehicle}', [EventVehicleController::class, 'destroy'])->name('event-vehicles.destroy');

    Route::post('/events/{event}/planner/message', [EventPlannerController::class, 'message'])->name('planner.message');
    Route::post('/events/{event}/place-options', [EventPlaceOptionController::class, 'store'])->name('event-place-options.store');
    Route::put('/event-place-options/{eventPlaceOption}', [EventPlaceOptionController::class, 'update'])->name('event-place-options.update');
    Route::get('/event-tasks/{eventTask}/form', [TaskFormController::class, 'show'])->name('event-tasks.form.show');
    Route::post('/event-tasks/{eventTask}/form/suggest', [TaskFormController::class, 'suggest'])->name('event-tasks.form.suggest');
    Route::post('/event-tasks/{eventTask}/form/media', [TaskFormController::class, 'uploadMedia'])->name('event-tasks.form.media');
    Route::put('/event-tasks/{eventTask}/form', [TaskFormController::class, 'update'])->name('event-tasks.form.update');
});

// ---------------------------------
// 🔓 Authenticated (non-role-specific)
// ---------------------------------
Route::middleware(['auth'])->group(function () {
    //Finances
    Route::prefix('clubs/{club}')->name('clubs.')->group(function () {
        Route::get('accounts', [\App\Http\Controllers\AccountController::class, 'index'])->name('accounts.index');
        Route::post('accounts', [\App\Http\Controllers\AccountController::class, 'store'])->name('accounts.store');
        Route::post('accounts/recalculate', [\App\Http\Controllers\AccountController::class, 'recalculate'])->name('accounts.recalculate');
        Route::put('accounts/{account}', [\App\Http\Controllers\AccountController::class, 'update'])->name('accounts.update');
        Route::delete('accounts/{account}', [\App\Http\Controllers\AccountController::class, 'destroy'])->name('accounts.destroy');

        Route::get('payment-concepts',                [ClubController::class, 'paymentConceptsIndex'])->name('payment-concepts.index');
        Route::post('payment-concepts',               [ClubController::class, 'paymentConceptsStore'])->name('payment-concepts.store');
        Route::get('payment-concepts/{paymentConcept}',   [ClubController::class, 'paymentConceptsShow'])->name('payment-concepts.show');
        Route::put('payment-concepts/{paymentConcept}',   [ClubController::class, 'paymentConceptsUpdate'])->name('payment-concepts.update');
        Route::delete('payment-concepts/{paymentConcept}',[ClubController::class, 'paymentConceptsDestroy'])->name('payment-concepts.destroy');

        // Pathfinder temp records (available to any authenticated user with club access)
        Route::get('temp-members', [\App\Http\Controllers\TempPathfinderController::class, 'listMembers'])->name('temp-members.index');
        Route::post('temp-members', [\App\Http\Controllers\TempPathfinderController::class, 'storeMember'])->name('temp-members.store');
        Route::get('temp-staff', [\App\Http\Controllers\TempPathfinderController::class, 'listStaff'])->name('temp-staff.index');
        Route::post('temp-staff', [\App\Http\Controllers\TempPathfinderController::class, 'storeStaff'])->name('temp-staff.store');
    });
    //Update password
    Route::put('/users/{id}/password', [StaffAdventurerController::class, 'updatePassword'])->name('users.updatePassword');
    Route::post('/staff', [StaffAdventurerController::class, 'store'])->name('staff.store');
    Route::put('/members/{id}', [MemberAdventurerController::class, 'update'])->name('members.update');
    Route::get('/staff/{staffId}/assigned-members', [StaffAdventurerController::class, 'getAssignedMembersByStaff']);
    Route::get('/clubs/{clubId}/classes', [ClubClassController::class, 'getByClubId'])->name('clubs.classes');
    Route::get('/club-class-reports/pdf', [ClubClassController::class, 'pdf'])->name('club-classes.pdf');
    Route::get('/club-class-reports/pdf-with-requirements', [ClubClassController::class, 'pdfWithRequirements'])->name('club-classes.pdf-with-requirements');

    //Reports
    Route::get('/pdf-assistance-reports/{id}/{date}/pdf', [ReportController::class, 'generateAssistancePDF'])->name('asistance-report.pdf');


    Route::prefix('assistance-reports')->group(function () {
        Route::get('/', [RepAssistanceAdvController::class, 'index']);
        Route::post('/', [RepAssistanceAdvController::class, 'store']);
        Route::get('/{id}', [RepAssistanceAdvController::class, 'show']);
        Route::put('/{id}', [RepAssistanceAdvController::class, 'update']);
        Route::delete('/{id}', [RepAssistanceAdvController::class, 'destroy']);
        Route::get('/check-today/{staffId}', [RepAssistanceAdvController::class, 'checkTodayReport']);
        Route::get('/by/{field}/{value}', [RepAssistanceAdvController::class, 'getBy']);
        Route::get('/by-date', [AssistanceReportController::class, 'getByDate']);
        Route::get('/by-range', [AssistanceReportController::class, 'getByDateRange']);

    });

    Route::get('/club-personal/dashboard', function () {
        $user = Auth::user();
        if ($user) {
            $user->setAttribute('assigned_class_id', session('assigned_class_id'));
            $user->setAttribute('assigned_class_name', session('assigned_class_name'));
        }
        return Inertia::render('ClubPersonal/ClubPersonalDashboard', [
            'auth_user' => $user
        ]);
    })->name('clubPersonal.dashboard');


    Route::get('/club-personal/assistance-report', [AssistanceReportController::class, 'index'])
        ->name('club.assistance_report');
    Route::get('/club-personal/assistance-report/activities', [AssistanceReportController::class, 'requirementActivities'])
        ->name('club.assistance_report.activities');

    Route::get('/club-personal/payments', [ClubPaymentController::class, 'index'])
        ->name('club.payments.index');
    Route::post('/club-personal/payments', [ClubPaymentController::class, 'store'])->name('club.payments.store');
    Route::put('/club-personal/payments/{payment}', [ClubPaymentController::class, 'update'])->name('club.payments.update');
    Route::delete('/club-personal/payments/{payment}', [ClubPaymentController::class, 'destroy'])->name('club.payments.destroy');

    Route::get('/staff/staff-record', [StaffAdventurerController::class, 'checkStaffRecord'])->name('staff.record');

    Route::get('/clubs/by-church-name', [ClubController::class, 'getByChurchNames'])->name('clubs.by-church-name');
});

Route::middleware(['auth', 'verified', 'profile:club_director,superadmin'])->group(function () {
    Route::get('/club-director/accounting-corrections', [AccountingCorrectionController::class, 'index'])
        ->name('club.director.accounting-corrections');
    Route::post('/club-director/accounting-corrections/payments/{payment}/reverse', [AccountingCorrectionController::class, 'reversePayment'])
        ->name('club.director.accounting-corrections.payments.reverse');
    Route::post('/club-director/accounting-corrections/expenses/{expense}/reverse', [AccountingCorrectionController::class, 'reverseExpense'])
        ->name('club.director.accounting-corrections.expenses.reverse');
    Route::post('/club-director/accounting-corrections/reimbursements/{expense}/reverse', [AccountingCorrectionController::class, 'reverseReimbursement'])
        ->name('club.director.accounting-corrections.reimbursements.reverse');
});

require __DIR__ . '/auth.php';
