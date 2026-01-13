<?php

use App\Http\Controllers\StaffAdventurerController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use App\Http\Controllers\ClubController;
use App\Http\Controllers\MemberAdventurerController;
use App\Http\Controllers\ParentAuthController;
use App\Models\Club;
use App\Http\Controllers\ChurchController;
use App\Http\Controllers\ParentMemberController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ClubClassController;
use App\Http\Controllers\LLMQueryController as AIQueryController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\AssistanceReportController;
use App\Http\Controllers\ClubPaymentController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\RepAssistanceAdvController;
use App\Models\SubRole;
use App\Http\Controllers\ReportController;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Controllers\WorkplanController;
use App\Http\Controllers\ClubSettingsController;

// ---------------------------------
// 🔗 Public Routes
// ---------------------------------

Route::get('/', function () {
    if (Auth::check()) {
        return redirect(RedirectIfAuthenticated::redirectPath());
    }

    return redirect('/login');
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

Route::get('/churches', [ChurchController::class, 'index']);
Route::post('/churches', [ChurchController::class, 'store']);

Route::get('/church-form', fn() => Inertia::render('Church/ChurchForm'))->name('church.form');

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', fn() => Inertia::render('Dashboard', [
        'auth_user' => auth()->user(),
    ]))->name('dashboard');
});

// ---------------------------------
// 🔐 Guest Routes (Parent Self-Registration)
// ---------------------------------
Route::middleware(['guest'])->group(function () {
Route::post('/setup/superadmin', [RegisteredUserController::class, 'storeSuperadmin'])->name('superadmin.setup.store');
Route::get('/register-parent', [ParentAuthController::class, 'showRegistrationForm'])->name('parent.register');
Route::post('/register-parent', [ParentAuthController::class, 'register']);
Route::get('/churches/{church}/clubs', [ClubController::class, 'getByChurch']);
});

Route::middleware(['auth', 'verified', 'profile:club_personal'])->group(function () {
    Route::get('/club-personal/workplan', [WorkplanController::class, 'index'])->name('club.personal.workplan');
    Route::get('/club-personal/workplan/pdf', [WorkplanController::class, 'pdf'])->name('club.personal.workplan.pdf');
    Route::get('/club-personal/workplan/data', [WorkplanController::class, 'data'])->name('club.personal.workplan.data');
    Route::get('/club-personal/workplan/ics', [WorkplanController::class, 'ics'])->name('club.personal.workplan.ics');
    Route::get('/club-personal/workplan/class-plans/pdf', [WorkplanController::class, 'classPlansPdf'])->name('club.personal.workplan.class-plans.pdf');
    Route::post('/club-personal/class-plans', [\App\Http\Controllers\ClassPlanController::class, 'store'])->name('club.personal.class-plans.store');
    Route::put('/club-personal/class-plans/{plan}', [\App\Http\Controllers\ClassPlanController::class, 'update'])->name('club.personal.class-plans.update');
    Route::delete('/club-personal/class-plans/{plan}', [\App\Http\Controllers\ClassPlanController::class, 'destroy'])->name('club.personal.class-plans.destroy');
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

    Route::get('/parent/workplan/data', [WorkplanController::class, 'data'])->name('parent.workplan.data');
    Route::get('/parent/workplan/pdf', [WorkplanController::class, 'pdf'])->name('parent.workplan.pdf');
    Route::get('/parent/workplan/ics', [WorkplanController::class, 'ics'])->name('parent.workplan.ics');
    Route::get('/parent/workplan/class-plans/pdf', [WorkplanController::class, 'classPlansPdf'])->name('parent.workplan.class-plans.pdf');
});

// ---------------------------------
// 🔵 Club Director Protected Routes
// ---------------------------------
Route::middleware(['auth', 'verified', 'profile:club_director'])->group(function () {

    // 🔷 Frontend Views
    Route::get('/director/children', [ParentMemberController::class, 'index'])->name('parent-links.index.director');

    Route::get('/club-director/dashboard', fn() => Inertia::render('ClubDirectorDashboard'))->name('club.dashboard');

    Route::get(
        '/club-director/my-club',
        fn() =>
        Inertia::render('ClubDirector/MyClub', ['auth_user' => auth()->user()])
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

    Route::get('/club-director/staff', function () {
        $authUser = auth()->user();
        $clubId = $authUser?->club_id;

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
    Route::post('/club-director/workplan/export', [WorkplanController::class, 'exportToMyChurchAdmin'])->name('club.workplan.export');
    Route::get('/club-director/workplan/pdf', [WorkplanController::class, 'pdf'])->name('club.workplan.pdf');
    Route::get('/club-director/workplan/ics', [WorkplanController::class, 'ics'])->name('club.workplan.ics');
    Route::get('/club-director/workplan/class-plans/pdf', [WorkplanController::class, 'classPlansPdf'])->name('club.workplan.class-plans.pdf');
    Route::put('/club-director/class-plans/{plan}/status', [\App\Http\Controllers\ClassPlanController::class, 'updateStatus'])->name('club.workplan.class-plans.status');
    Route::get('/club-director/church/invite-code', [\App\Http\Controllers\ChurchInviteCodeController::class, 'show'])->name('club.director.church.invite-code');
    Route::post('/club-director/church/invite-code/regenerate', [\App\Http\Controllers\ChurchInviteCodeController::class, 'regenerate'])->name('club.director.church.invite-code.regenerate');
    Route::get('/club-director/settings', [ClubSettingsController::class, 'index'])->name('club.settings');
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

    Route::get('/church/{churchId}/clubs', [ClubController::class, 'getClubsByChurchId'])->name('church.clubs');
    Route::post('/club-user', [ClubController::class, 'selectClub'])->name('club.select');

    // Members
    Route::post('/members', [MemberAdventurerController::class, 'store'])->name('members.store');
    Route::get('/clubs/{id}/members', [MemberAdventurerController::class, 'byClub'])->name('clubs.members');
    Route::delete('/members/{id}', [MemberAdventurerController::class, 'destroy'])->name('members.destroy');
    Route::get('/members/{id}/export-word', [MemberAdventurerController::class, 'exportWord'])->name('members.export-word');
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

    //Reports
    Route::post('/assistance-reports/filter', [ReportController::class, 'assistanceReportsDirector'])->name('assistance-reports.director');
    Route::get('/financial-report/bootstrap', [ReportController::class, 'financialReportPreload'])->name('financial.preload');
    Route::get('/financial-report/report', [ReportController::class, 'financialReport'])->name('financial.report');
    Route::get('/financial-report/accounts', [ReportController::class, 'financialAccountBalances'])->name('financial.accounts');
    Route::get('/financial-report/accounts/pdf', [ReportController::class, 'financialAccountBalancesPdf'])->name('financial.accounts.pdf');

});

// ---------------------------------
// 🔓 Authenticated (non-role-specific)
// ---------------------------------
Route::middleware(['auth'])->group(function () {
    //Finances
    Route::prefix('clubs/{club}')->name('clubs.')->group(function () {
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
    Route::get('/staff/{staffId}/assigned-members', [StaffAdventurerController::class, 'getAssignedMembersByStaff']);
    Route::get('/clubs/{clubId}/classes', [ClubClassController::class, 'getByClubId'])->name('clubs.classes');

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
        return Inertia::render('ClubPersonal/ClubPersonalDashboard', [
            'auth_user' => Auth::user()
        ]);
    })->name('clubPersonal.dashboard');


    Route::get('/club-personal/assistance-report', [AssistanceReportController::class, 'index'])
        ->name('club.assistance_report');

    Route::get('/club-personal/payments', [ClubPaymentController::class, 'index'])
        ->name('club.payments.index');
    Route::post('/club-personal/payments', [ClubPaymentController::class, 'store'])->name('club.payments.store');

    Route::get('/staff/staff-record', [StaffAdventurerController::class, 'checkStaffRecord'])->name('staff.record');

    Route::get('/clubs/by-church-name', [ClubController::class, 'getByChurchNames'])->name('clubs.by-church-name');
});

require __DIR__ . '/auth.php';
