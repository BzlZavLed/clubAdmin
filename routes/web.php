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
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RepAssistanceAdvController;
use App\Models\SubRole;
use App\Http\Controllers\ReportController;

// ---------------------------------
// 🔗 Public Routes
// ---------------------------------

Route::get('/', fn() => redirect('/login'));
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

// ---------------------------------
// 🔐 Guest Routes (Parent Self-Registration)
// ---------------------------------
Route::middleware(['guest'])->group(function () {
    Route::get('/register-parent', [ParentAuthController::class, 'showRegistrationForm'])->name('parent.register');
    Route::post('/register-parent', [ParentAuthController::class, 'register']);
    Route::get('/churches/{church}/clubs', [ClubController::class, 'getByChurch']);
});

// ---------------------------------
// 🟣 Parent-Only Routes (Authenticated)
// ---------------------------------
Route::middleware(['auth', 'verified', 'auth.parent'])->group(function () {
    Route::get('/parent/apply', fn() => Inertia::render('Parent/Apply', [
        'auth_user' => auth()->user(),
        'clubs' => Club::all(),
    ]))->name('parent.apply');

    Route::post('/parent/apply', [MemberAdventurerController::class, 'store'])->name('parent.apply.submit');
    Route::get('/parent/children', [ParentMemberController::class, 'index'])->name('parent-links.index.parent');
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
        '/club-director/members',
        fn() =>
        Inertia::render('ClubDirector/Members', ['auth_user' => auth()->user()])
    )->name('club.members');

    Route::get('/club-director/staff', function () {
        return Inertia::render('ClubDirector/Staff', [
            'auth_user' => auth()->user(),
            'sub_roles' => SubRole::all(),
        ]);
    })->name('club.staff');

    Route::get('/club-director/reports/assistance', function () {
        return Inertia::render('ClubDirector/Reports/Assistance', [
            'auth_user' => auth()->user(),
            'sub_roles' => SubRole::all(),
        ]);
    })->name('club.reports.assistance');

    // 🟢 API Endpoints

    // Clubs
    Route::get('/clubs/by-ids', [ClubController::class, 'getByIds'])->name('clubs.by-ids');
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
    Route::post('/members/export-zip', [MemberAdventurerController::class, 'exportZip'])->name('members.export-zip');
    Route::post('/members/class-member-assignments', [MemberAdventurerController::class, 'assignMember'])->name('members.assign');
    Route::post('/members/class-member-assignments/undo', [MemberAdventurerController::class, 'undoLastAssignment'])->name('members.assignment.undo');

    // Staff
    Route::get('/clubs/{clubId}/staff/{churchId?}', [StaffAdventurerController::class, 'byClub'])->name('clubs.staff');
    Route::post('/staff', [StaffAdventurerController::class, 'store'])->name('staff.store');
    Route::post('/staff/create-user', [StaffAdventurerController::class, 'createUser'])->name('staff.createUser');
    Route::get('/staff/{id}/export-word', [StaffAdventurerController::class, 'exportWord'])->name('staff.export-word');
    Route::post('/staff/update-user-account', [StaffAdventurerController::class, 'updateStaffUserAccount'])->name('staff.updateUserAccount');
    Route::post('/staff/update-staff-account', [StaffAdventurerController::class, 'updateStaffAccount'])->name('staff.updateStaffAccount');
    Route::put('/staff/update-class', [StaffAdventurerController::class, 'updateAssignedClass'])->name('staff.update-class');
    Route::put('/staff/{id}', [StaffAdventurerController::class, 'update'])->name('staff.update');


    // AI
    Route::post('/nl-query', [AIQueryController::class, 'handle']);

    // Export ZIP
    Route::post('/export/{type}/zip', [ExportController::class, 'exportZip'])->name('export.zip');

    // Debug route
    Route::get('/test-template-access', function () {
        $path = storage_path('app/templates/template_adventurer_new.docx');
        return file_exists($path) ? response()->download($path) : 'Template not found.';
    });

    //Reports
    Route::post('/assistance-reports/filter', [ReportController::class, 'assistanceReportsDirector'])->name('assistance-reports.director');

});

// ---------------------------------
// 🔓 Authenticated (non-role-specific)
// ---------------------------------
Route::middleware(['auth'])->group(function () {
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

    Route::get('/staff/staff-record', [StaffAdventurerController::class, 'checkStaffRecord'])->name('staff.record');

    Route::get('/clubs/by-church-name', [ClubController::class, 'getByChurchNames'])->name('clubs.by-church-name');
});

require __DIR__ . '/auth.php';
