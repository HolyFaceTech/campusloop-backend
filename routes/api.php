<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
// Admin
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\StrandController;
use App\Http\Controllers\Api\SystemSettingController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\AdminELibraryController;
use App\Http\Controllers\Api\AdminGradeController;
use App\Http\Controllers\Api\AdminClassroomController;
use App\Http\Controllers\Api\AdminClassworkController;
use App\Http\Controllers\Api\AdminCommentController;
use App\Http\Controllers\Api\AdminClassroomStudentController;
use App\Http\Controllers\Api\AdminClassroomGradeController;
use App\Http\Controllers\Api\AdminFormController;
use App\Http\Controllers\Api\AdminFileController;
// Teacher
use App\Http\Controllers\Api\ClassroomController;
use App\Http\Controllers\Api\ClassworkController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\ClassroomStudentController;
use App\Http\Controllers\Api\ClassroomGradeController;
use App\Http\Controllers\Api\FormController;
use App\Http\Controllers\Api\FormQuestionController;
use App\Http\Controllers\Api\ELibraryController;
use App\Http\Controllers\Api\AdvisoryClassController;
use App\Http\Controllers\Api\TeacherFileController;
use App\Http\Controllers\Api\TeacherCalendarController;
// Student
use App\Http\Controllers\Api\StudentClassroomController;
use App\Http\Controllers\Api\StudentFormController;
use App\Http\Controllers\Api\StudentELibraryController;
use App\Http\Controllers\Api\StudentGradeController;
use App\Http\Controllers\Api\StudentFileController;

// Public Auth Routes (Hindi kailangan ng token)
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/resend-verification', [AuthController::class, 'resendVerificationEmail']);
Route::post('/verify-email', [AuthController::class, 'verifyEmail']);

// Protected Routes (Kailangan naka-login/may token bago ma-access)
Route::middleware('auth:sanctum')->group(function () {

    // Admin User Records
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    Route::post('/users/bulk-delete', [UserController::class, 'bulkDestroy']);

    // Admin Academic Management - Strands
    Route::get('/strands', [StrandController::class, 'index']);
    Route::post('/strands', [StrandController::class, 'store']);
    Route::put('/strands/{id}', [StrandController::class, 'update']);
    Route::delete('/strands/{id}', [StrandController::class, 'destroy']);

    // Admin System Settings
    Route::get('/settings', [SystemSettingController::class, 'index']);
    Route::post('/settings', [SystemSettingController::class, 'store']);
    Route::post('/settings/reset', [SystemSettingController::class, 'reset']);

    // Admin Academic Management - Subjects
    Route::get('/subjects', [SubjectController::class, 'index']);
    Route::post('/subjects', [SubjectController::class, 'store']);
    Route::put('/subjects/{id}', [SubjectController::class, 'update']);
    Route::delete('/subjects/{id}', [SubjectController::class, 'destroy']);
    Route::post('/subjects/bulk-delete', [SubjectController::class, 'bulkDelete']);

    // Admin Content Approval - Announcement
    Route::get('/announcements', [AnnouncementController::class, 'index']);
    Route::post('/announcements', [AnnouncementController::class, 'store']);
    Route::post('/announcements/bulk-delete', [AnnouncementController::class, 'bulkDelete']);
    Route::put('/announcements/{id}', [AnnouncementController::class, 'update']);
    Route::delete('/announcements/{id}', [AnnouncementController::class, 'destroy']);

    // Admin Calendar
    Route::get('/calendar/admin/events', [CalendarController::class, 'getAdminEvents']);
    Route::get('/calendar/active-indicator', [CalendarController::class, 'checkActiveIndicator']);

    // Admin E-Library
    Route::get('/admin/e-libraries', [AdminELibraryController::class, 'index']);
    Route::post('/admin/e-libraries/approve', [AdminELibraryController::class, 'bulkApprove']);
    Route::post('/admin/e-libraries/decline', [AdminELibraryController::class, 'bulkDecline']);
    Route::post('/admin/e-libraries/delete', [AdminELibraryController::class, 'bulkDelete']);

    // Admin Student Grades
    Route::get('/admin/student-grades', [AdminGradeController::class, 'index']);
    Route::get('/admin/student-grades/{studentId}', [AdminGradeController::class, 'showStudentGrades']);
    Route::post('/admin/student-grades/approve', [AdminGradeController::class, 'approveGrade']);
    Route::post('/admin/student-grades/decline', [AdminGradeController::class, 'declineGrade']);

    // Admin Classroom Routes
    Route::get('admin/classrooms', [AdminClassroomController::class, 'index']);
    Route::post('admin/classrooms/bulk-delete', [AdminClassroomController::class, 'destroyBulk']);
    Route::get('admin/classrooms/{id}', [AdminClassroomController::class, 'show']);
    
    // Admin Classwork
    Route::get('admin/classrooms/{id}/classworks', [AdminClassworkController::class, 'index']);
    Route::post('admin/classworks/bulk-delete', [AdminClassworkController::class, 'destroyBulk']);

    // Admin Classwork Submission
    Route::get('admin/classworks/{id}/submissions', [AdminClassworkController::class, 'submissions']);

    // Admin Classwork Comment Control
    Route::delete('admin/comments/{id}', [AdminCommentController::class, 'destroy']);

    // Admin Classroom People
    Route::get('admin/classrooms/{id}/students', [AdminClassroomStudentController::class, 'index']);
    Route::post('admin/classrooms/{id}/students/approve', [AdminClassroomStudentController::class, 'approve']);
    Route::post('admin/classrooms/{id}/students/remove', [AdminClassroomStudentController::class, 'remove']);

    // Admin Classroom Grades
    Route::get('admin/classrooms/{id}/grades', [AdminClassroomGradeController::class, 'index']);

    // Admin Forms
    Route::get('admin/forms', [AdminFormController::class, 'index']);
    Route::post('admin/forms/bulk-delete', [AdminFormController::class, 'destroyBulk']);
    Route::get('admin/forms/{id}', [AdminFormController::class, 'show']);
    Route::get('admin/forms/{id}/respondents', [AdminFormController::class, 'respondents']);
    Route::delete('admin/forms/{formId}/submissions/{submissionId}/unsubmit', [AdminFormController::class, 'unsubmit']);
    Route::get('admin/forms/{id}/print', [AdminFormController::class, 'printTeacherForm']);
    Route::get('admin/forms/{id}/submissions/{subId}/print', [AdminFormController::class, 'printStudentForm']);

    // Admin Files
    Route::get('admin/folders', [AdminFileController::class, 'folders']);
    Route::get('admin/folders/{userId}/files', [AdminFileController::class, 'userFiles']);
    Route::post('admin/files/download-zip', [AdminFileController::class, 'downloadZip']);
    Route::post('admin/files/bulk-delete', [AdminFileController::class, 'bulkDelete']);

    // Teacher Classrooms
    Route::get('/classrooms', [ClassroomController::class, 'index']);
    Route::post('/classrooms', [ClassroomController::class, 'store']);
    Route::put('/classrooms/{id}', [ClassroomController::class, 'update']);
    Route::delete('/classrooms/{id}', [ClassroomController::class, 'destroy']);
    Route::get('/classrooms/{id}', [ClassroomController::class, 'show']); 

    // Teacher Classworks
    Route::get('/classrooms/{classroomId}/classworks', [ClassworkController::class, 'index']);
    Route::post('/classrooms/{classroomId}/classworks', [ClassworkController::class, 'store']);
    Route::put('/classworks/{id}', [ClassworkController::class, 'update']);
    Route::delete('/classworks/{id}', [ClassworkController::class, 'destroy']);

    // Comment & Reply
    Route::post('/classworks/{id}/comments', [CommentController::class, 'store']);

    // Grading and Unsubmit Submission
    Route::get('/classworks/{id}/submissions', [ClassworkController::class, 'getSubmissions']);
    Route::post('/classworks/{id}/submissions/{studentId}/grade', [ClassworkController::class, 'gradeSubmission']);
    Route::post('/classworks/{id}/submissions/{studentId}/return', [ClassworkController::class, 'returnSubmission']);

    // Teacher Classroom Students
    Route::get('/classrooms/{classroomId}/students', [ClassroomStudentController::class, 'index']);
    Route::post('/classrooms/{classroomId}/students/approve', [ClassroomStudentController::class, 'approve']);
    Route::post('/classrooms/{classroomId}/students/remove', [ClassroomStudentController::class, 'remove']);

    // Teacher Classroom Grades
    Route::get('/classrooms/{classroomId}/grades', [ClassroomGradeController::class, 'index']);

    // Teacher Forms
    Route::get('/forms', [FormController::class, 'index']);
    Route::post('/forms', [FormController::class, 'store']);
    Route::put('/forms/{id}', [FormController::class, 'update']);
    Route::delete('/forms/{id}', [FormController::class, 'destroy']);
    Route::post('/forms/{id}/duplicate', [FormController::class, 'duplicate']);
    Route::get('/forms/{id}', [FormController::class, 'show']);
    Route::get('/forms/{id}/respondents', [FormController::class, 'respondents']);
    Route::post('/forms/{id}/questions', [FormQuestionController::class, 'store']);
    Route::put('/questions/{id}', [FormQuestionController::class, 'update']);
    Route::delete('/questions/{id}', [FormQuestionController::class, 'destroy']);
    
    // Teacher ELibrary
    Route::apiResource('e-libraries', ELibraryController::class);

    // Teacher Advisory Class
    Route::apiResource('advisory-classes', AdvisoryClassController::class);
    Route::get('advisory-classes/{id}/available-students', [AdvisoryClassController::class, 'getAvailableStudents']);
    Route::post('advisory-classes/{id}/add-students', [AdvisoryClassController::class, 'addStudents']);
    Route::delete('advisory-classes/{classId}/students/{studentId}', [AdvisoryClassController::class, 'removeStudent']);
    Route::get('/advisory-classes/{classId}/students/{studentId}/grades', [AdvisoryClassController::class, 'getStudentGrades']);
    Route::post('/advisory-classes/{classId}/students/{studentId}/grades', [AdvisoryClassController::class, 'storeStudentGrade']);
    Route::put('/advisory-classes/{classId}/students/{studentId}/grades/{gradeId}', [AdvisoryClassController::class, 'updateStudentGrade']);

    // Teacher File
    Route::get('teacher/files', [TeacherFileController::class, 'index']);
    Route::post('teacher/files/download-zip', [TeacherFileController::class, 'downloadZip']);

    // Teacher Calendar
    Route::get('calendar/teacher/events', [TeacherCalendarController::class, 'events']);

    // Student Classrooms
    Route::get('/student/classrooms', [StudentClassroomController::class, 'index']);
    Route::post('/student/classrooms/join', [StudentClassroomController::class, 'joinClassroom']);
    Route::get('/student/classrooms/{id}', [StudentClassroomController::class, 'show']);
    Route::get('/student/classrooms/{id}/stream', [StudentClassroomController::class, 'stream']);
    Route::post('/student/classworks/{id}/submit', [StudentClassroomController::class, 'submitWork']);
    Route::post('/student/classworks/{id}/unsubmit', [StudentClassroomController::class, 'unsubmitWork']);
    Route::get('/student/forms/{id}', [StudentFormController::class, 'show']);
    Route::post('/student/forms/{id}/submit', [StudentFormController::class, 'submit']);
    Route::get('/student/classrooms/{id}/grades', [StudentClassroomController::class, 'grades']);

    // Student E-Library
    Route::get('student/e-libraries', [StudentELibraryController::class, 'index']);

    // Student Grades
    Route::get('student/grades', [StudentGradeController::class, 'index']);

    // Student Files
    Route::get('student/files', [StudentFileController::class, 'index']);
    Route::post('student/files/download-zip', [StudentFileController::class, 'downloadZip']);

    // Kunin ang current logged-in user data
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Logout Route
    Route::post('/logout', [AuthController::class, 'logout']);
    
});