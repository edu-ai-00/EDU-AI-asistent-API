<?php

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AdminChatController;
use App\Http\Controllers\Api\ChatMessageController;
use App\Http\Controllers\Api\ChatSessionController;
use App\Http\Controllers\Api\AdminClassroomController;
use App\Http\Controllers\Api\AdminTeamController;
use App\Http\Controllers\Api\AdminNewsController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AdminWorkTimeController;
use App\Http\Controllers\Api\WorkHeartbeatController;
use App\Http\Controllers\Api\AdminUserMergeController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BlockStatController;
use App\Http\Controllers\Api\BookmarkController;
use App\Http\Controllers\Api\FsrsProfileController;
use App\Http\Controllers\Api\PracticeCardController;
use App\Http\Controllers\Api\PracticeReviewLogController;
use App\Http\Controllers\Api\ProxyImageController;
use App\Http\Controllers\Api\CodeController;
use App\Http\Controllers\Api\ContentFeedbackController;
use App\Http\Controllers\Api\DebugReportController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\EloBackfillController;
use App\Http\Controllers\Api\EloInteractionController;
use App\Http\Controllers\Api\EloProfileController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\GamificationConfigController;
use App\Http\Controllers\Api\GpfController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\OAuthController;
use App\Http\Controllers\Api\UserAchievementController;
use App\Http\Controllers\Api\QuizAttemptController;
use App\Http\Controllers\Api\CourseSkillsController;
use App\Http\Controllers\Api\SkillConfigController;
use App\Http\Controllers\Api\SkillEventController;
use App\Http\Controllers\Api\StudentSkillController;
use App\Http\Controllers\Api\UserCourseController;
use App\Http\Controllers\Api\UserProgressController;
use App\Http\Controllers\Api\UserStatsController;
use App\Http\Controllers\Api\UserThemeController;
use App\Http\Controllers\Api\VectorController;
use Illuminate\Support\Facades\Route;

// Health check
Route::get('/health', fn () => response()->json(['status' => 'ok']));

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/register-verified', [AuthController::class, 'registerVerified']);
Route::post('/login', [AuthController::class, 'login']);

// Guest routes (public)
Route::post('/guest/register', [AuthController::class, 'registerGuest']);

// OAuth social sign-in (public) — id_token exchange, throttled.
Route::post('/auth/google', [OAuthController::class, 'google'])
    ->middleware('throttle:oauth');
Route::post('/auth/apple', [OAuthController::class, 'apple'])
    ->middleware('throttle:oauth');
Route::post('/auth/microsoft', [OAuthController::class, 'microsoft'])
    ->middleware('throttle:oauth');

// Code resolver (student login code or course PIN) — throttled to deter brute-force.
Route::post('/resolve-code', [CodeController::class, 'resolve'])
    ->middleware('throttle:code-resolve');

// Image proxy for Flutter web (CORS bypass) — SSRF-hardened controller, throttled.
Route::get('/proxy/image', [ProxyImageController::class, 'show'])
    ->middleware(['auth.optional', 'throttle:image-proxy']);

// Email verification routes (public - no auth required)
Route::prefix('email')->group(function () {
    Route::post('/check', [EmailVerificationController::class, 'check']);
    Route::post('/send-code', [EmailVerificationController::class, 'sendCode'])
        ->middleware('throttle:email-send-code');
    // auth.optional resolves the caller's guest token (still active at verify
    // time, before the client switches to the returned token) so the guest's
    // data can be folded into a pre-existing account (BR-4FTCFH).
    Route::post('/verify', [EmailVerificationController::class, 'verify'])
        ->middleware(['auth.optional', 'throttle:email-verify']);
});

// Gamification config (public, cached by app)
Route::get('/gamification/config', [GamificationConfigController::class, 'show']);

// ─────────────────────────────────────────────────────────────────────────────
// Admin auth routes (public — no auth required)
// ─────────────────────────────────────────────────────────────────────────────
Route::prefix('admin/auth')->group(function () {
    Route::post('/send-code', [AdminAuthController::class, 'sendCode'])
        ->middleware('throttle:admin-auth-send');
    Route::post('/verify', [AdminAuthController::class, 'verify'])
        ->middleware('throttle:admin-auth-verify');
});

// Admin auth routes (require Sanctum token)
Route::middleware('auth:sanctum')->prefix('admin/auth')->group(function () {
    Route::post('/logout', [AdminAuthController::class, 'logout']);
    Route::post('/refresh', [AdminAuthController::class, 'refresh']);
    Route::get('/me', [AdminAuthController::class, 'me']);
});

// Course routes - public read access (shows published only without auth)
// With auth token: shows all courses. Without: only published.
Route::middleware('auth.optional')->group(function () {
    Route::get('/courses', [CourseController::class, 'index']);
    Route::get('/courses/check-updates', [CourseController::class, 'checkUpdates']);
    Route::get('/courses/pin/{pin}', [CourseController::class, 'findByPin']); // Lookup by pin
    Route::get('/courses/{id}', [CourseController::class, 'show']);
});

// Protected routes (user authentication via magic link)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);

    // Guest account upgrade
    Route::post('/guest/claim', [AuthController::class, 'claimGuestAccount']);

    // Course download - requires authentication
    Route::get('/courses/{id}/download', [CourseController::class, 'download']); // R2 download URL

    // User courses - track downloaded courses and progress
    Route::prefix('user/courses')->group(function () {
        Route::get('/', [UserCourseController::class, 'index']);
        Route::post('/', [UserCourseController::class, 'store']);
        Route::put('/{courseId}', [UserCourseController::class, 'update']);
        Route::delete('/{courseId}', [UserCourseController::class, 'destroy']);
    });

    // User stats - profile stats (level, XP, streak, etc.)
    Route::get('/user/stats', [UserStatsController::class, 'show']);
    Route::put('/user/stats', [UserStatsController::class, 'update']);

    // User progress - lesson answers and completion
    Route::get('/user/progress', [UserProgressController::class, 'index']);
    Route::post('/user/progress', [UserProgressController::class, 'store']);

    // Bookmarks
    Route::get('/user/bookmarks', [BookmarkController::class, 'index']);
    Route::post('/user/bookmarks', [BookmarkController::class, 'store']);
    Route::post('/user/bookmarks/sync', [BookmarkController::class, 'sync']);
    Route::post('/user/bookmarks/delete', [BookmarkController::class, 'destroyByKey']);
    Route::delete('/user/bookmarks/{id}', [BookmarkController::class, 'destroy']);

    // Quiz attempts
    Route::post('/user/quiz-attempts', [QuizAttemptController::class, 'store']);
    Route::get('/user/quiz-attempts', [QuizAttemptController::class, 'index']);

    // Content feedback
    Route::post('/content-feedback', [ContentFeedbackController::class, 'store']);

    // ELO Profile
    Route::get('/user/elo-profile', [EloProfileController::class, 'show']);
    Route::put('/user/elo-profile', [EloProfileController::class, 'update']);

    // ELO Interactions
    Route::post('/elo/interactions', [EloInteractionController::class, 'store']);
    Route::post('/elo/interactions/batch', [EloInteractionController::class, 'storeBatch']);

    // Work-time activity heartbeats (BR-9SAH2R)
    Route::post('/work/heartbeats', [WorkHeartbeatController::class, 'store']);

    // Block Stats
    Route::get('/blocks/stats', [BlockStatController::class, 'index']);

    // Achievements (offline-first sync)
    Route::get('/user/achievements', [UserAchievementController::class, 'index']);
    Route::post('/user/achievements', [UserAchievementController::class, 'store']);

    // Skill events (batch insert from app)
    Route::post('/skill-events', [SkillEventController::class, 'store']);

    // Practice cards (FSRS spaced repetition)
    Route::get('/user/practice-cards', [PracticeCardController::class, 'index']);
    Route::post('/user/practice-cards', [PracticeCardController::class, 'store']);

    // Practice review logs
    Route::get('/user/review-logs', [PracticeReviewLogController::class, 'index']);
    Route::post('/user/review-logs', [PracticeReviewLogController::class, 'store']);

    // FSRS student profile
    Route::get('/user/fsrs-profile', [FsrsProfileController::class, 'show']);
    Route::put('/user/fsrs-profile', [FsrsProfileController::class, 'update']);

    // Skill configs for all enrolled courses
    Route::get('/user/skill-configs', [SkillConfigController::class, 'index']);

    // Student skill vector (own or by teacher)
    Route::get('/students/{id}/skill-vector', [StudentSkillController::class, 'show']);

    // Canonical GPF vector dimension labels (read-only reference for the app)
    Route::get('/gpf/dimensions', [GpfController::class, 'dimensions']);

    // Course skill config (read)
    Route::get('/courses/{id}/skill-config', [SkillConfigController::class, 'show']);

    // User themes (custom theme CRUD + sync)
    Route::prefix('user/themes')->group(function () {
        Route::get('/', [UserThemeController::class, 'index']);
        Route::post('/', [UserThemeController::class, 'store']);
        Route::post('/sync', [UserThemeController::class, 'sync']);
        Route::put('/{id}', [UserThemeController::class, 'update']);
        Route::delete('/{id}', [UserThemeController::class, 'destroy']);
        Route::post('/{id}/activate', [UserThemeController::class, 'activate']);
    });

    // Chat
    Route::prefix('chat')->group(function () {
        Route::get('/sessions', [ChatSessionController::class, 'index']);
        Route::post('/sessions', [ChatSessionController::class, 'store']);
        Route::delete('/sessions/{id}', [ChatSessionController::class, 'destroy']);
        Route::post('/sessions/{id}/generate-title', [ChatSessionController::class, 'generateTitle']);
        Route::get('/sessions/{id}/messages', [ChatMessageController::class, 'index']);
        Route::post('/sessions/{id}/messages', [ChatMessageController::class, 'store'])
            ->middleware('throttle:chat-messages');
        Route::get('/sessions/{id}/stream', [ChatMessageController::class, 'stream']);
        Route::get('/messages', [ChatMessageController::class, 'allMessages']);
        Route::put('/messages/{id}/feedback', [ChatMessageController::class, 'updateFeedback']);
    });

    // News (read-only for users)
    Route::get('/news', [NewsController::class, 'index']);
    Route::get('/news/{id}', [NewsController::class, 'show']);
    Route::post('/news/{id}/read', [NewsController::class, 'markRead']);

});

// Debug Reports (outside auth:sanctum so reports can be sent even when auth is broken)
Route::middleware('auth.optional')->group(function () {
    Route::post('/debug-reports', [DebugReportController::class, 'store']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Admin-only routes (API key or admin role)
// ─────────────────────────────────────────────────────────────────────────────
Route::middleware('admin')->group(function () {
    // Course restore & backfill (admin-only operations)
    Route::post('/courses/{id}/restore', [CourseController::class, 'restore']);
    Route::post('/admin/courses/backfill', [CourseController::class, 'backfill']);

    // Content feedback management
    Route::get('/admin/content-feedback', [ContentFeedbackController::class, 'index']);
    Route::get('/admin/content-feedback/{id}', [ContentFeedbackController::class, 'show']);

    // Debug reports management
    Route::get('/admin/debug-reports', [DebugReportController::class, 'index']);
    Route::get('/admin/debug-reports/{id}', [DebugReportController::class, 'show']);

    // ELO management
    Route::get('/admin/elo/profiles', [EloProfileController::class, 'adminIndex']);
    Route::get('/admin/elo/profile/{userId}', [EloProfileController::class, 'adminByUser']);
    Route::get('/admin/elo/interactions/user/{userId}', [EloInteractionController::class, 'adminByUser']);
    Route::get('/admin/elo/interactions/course/{courseId}', [EloInteractionController::class, 'adminByCourse']);
    Route::get('/admin/elo/interactions/course/{courseId}/export', [EloInteractionController::class, 'exportCsv']);
    Route::get('/admin/blocks/stats', [BlockStatController::class, 'index']);

    // ELO backfill (admin-only)
    Route::get('/admin/elo/backfill/preview/{courseId}', [EloBackfillController::class, 'preview']);
    Route::post('/admin/elo/backfill/run/{courseId}', [EloBackfillController::class, 'run']);
    Route::post('/admin/elo/backfill/timestamps/{courseId}', [EloBackfillController::class, 'runTimestampBackfill']);

    // Asset management
    Route::get('/admin/assets', [AssetController::class, 'index']);
    Route::post('/admin/assets/upload', [AssetController::class, 'store']);
    Route::delete('/admin/assets/{id}', [AssetController::class, 'destroy']);

    // Team management (admin-only)
    Route::get('/admin/team', [AdminTeamController::class, 'index']);
    Route::post('/admin/team', [AdminTeamController::class, 'store']);
    Route::put('/admin/team/{id}', [AdminTeamController::class, 'update']);
    Route::delete('/admin/team/{id}', [AdminTeamController::class, 'destroy']);

    // News management (admin-only)
    Route::get('/admin/news', [AdminNewsController::class, 'index']);
    Route::post('/admin/news', [AdminNewsController::class, 'store']);
    Route::get('/admin/news/{id}', [AdminNewsController::class, 'show']);
    Route::put('/admin/news/{id}', [AdminNewsController::class, 'update']);
    Route::delete('/admin/news/{id}', [AdminNewsController::class, 'destroy']);

    // Vector management (admin-only)
    Route::get('/admin/vectors', [VectorController::class, 'index']);
    Route::post('/admin/vectors', [VectorController::class, 'store']);
    Route::get('/admin/vectors/{id}', [VectorController::class, 'show']);
    Route::put('/admin/vectors/{id}', [VectorController::class, 'update']);
    Route::delete('/admin/vectors/{id}', [VectorController::class, 'destroy']);
    Route::get('/admin/vectors/{id}/dimensions', [VectorController::class, 'dimensionsIndex']);
    Route::post('/admin/vectors/{id}/dimensions', [VectorController::class, 'dimensionsStore']);
    Route::post('/admin/vectors/{id}/dimensions/bulk', [VectorController::class, 'dimensionsBulkStore']);
    Route::put('/admin/vectors/{id}/dimensions/{dimId}', [VectorController::class, 'dimensionsUpdate']);
    Route::delete('/admin/vectors/{id}/dimensions/{dimId}', [VectorController::class, 'dimensionsDestroy']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Admin OR Teacher routes
// ─────────────────────────────────────────────────────────────────────────────
Route::middleware('admin:admin,teacher')->group(function () {
    // Course CRUD (teacher.course gates access for teachers)
    Route::post('/courses', [CourseController::class, 'store']);
    Route::post('/courses/upload', [CourseController::class, 'upload']);
    Route::middleware('teacher.course')->group(function () {
        Route::put('/courses/{id}', [CourseController::class, 'update']);
        Route::delete('/courses/{id}', [CourseController::class, 'destroy']);
    });

    // Admin download
    Route::get('/admin/courses/{id}/download', [CourseController::class, 'download'])->middleware('teacher.course');

    // User management
    Route::get('/admin/users', [AdminUserController::class, 'index']);
    Route::get('/admin/users/merge/preview', [AdminUserMergeController::class, 'preview']);
    Route::post('/admin/users/merge', [AdminUserMergeController::class, 'merge']);
    Route::get('/admin/users/{userId}/skills', [AdminUserController::class, 'skills']);
    Route::get('/admin/users/{userId}/work-time', [AdminWorkTimeController::class, 'show']);
    Route::get('/admin/users/{id}', [AdminUserController::class, 'show']);
    Route::put('/admin/users/{id}', [AdminUserController::class, 'update']);

    // Classroom management
    Route::post('/admin/classrooms', [AdminClassroomController::class, 'store']);
    Route::get('/admin/classrooms', [AdminClassroomController::class, 'index']);
    Route::get('/admin/classrooms/{id}', [AdminClassroomController::class, 'show']);
    Route::put('/admin/classrooms/{id}', [AdminClassroomController::class, 'update']);
    Route::delete('/admin/classrooms/{id}', [AdminClassroomController::class, 'destroy']);
    Route::post('/admin/classrooms/{id}/students', [AdminClassroomController::class, 'addStudents']);
    Route::get('/admin/classrooms/{id}/courses', [AdminClassroomController::class, 'getCourses']);
    Route::post('/admin/classrooms/{id}/courses', [AdminClassroomController::class, 'assignCourses']);
    Route::delete('/admin/classrooms/{id}/courses/{courseId}', [AdminClassroomController::class, 'removeCourse']);
    Route::get('/admin/classrooms/{id}/enrolled-courses', [AdminClassroomController::class, 'enrolledCourses']);
    Route::get('/admin/classrooms/{id}/skills/overview', [AdminClassroomController::class, 'skillsOverview']);

    // Progress management (teacher.course gates by courseId param)
    Route::get('/admin/progress/{courseId}', [UserProgressController::class, 'adminByCourse'])->middleware('teacher.course:courseId');
    Route::get('/admin/progress/{courseId}/stats', [UserProgressController::class, 'adminCourseStats'])->middleware('teacher.course:courseId');
    Route::get('/admin/progress/{courseId}/answers', [UserProgressController::class, 'adminCourseAnswers'])->middleware('teacher.course:courseId');
    Route::get('/admin/progress/{courseId}/answers/export', [UserProgressController::class, 'adminCourseAnswersExport'])->middleware('teacher.course:courseId');
    Route::get('/admin/progress/user/{userId}', [UserProgressController::class, 'adminByUser']);

    // Quiz attempts management
    Route::get('/admin/quiz-attempts/{courseId}', [QuizAttemptController::class, 'adminByCourse'])->middleware('teacher.course:courseId');
    Route::get('/admin/quiz-attempts/user/{userId}', [QuizAttemptController::class, 'adminByUser']);

    // Course skill config (admin read + write) + skills overview/stats
    Route::middleware('teacher.course')->group(function () {
        Route::get('/admin/courses/{id}/skill-config', [SkillConfigController::class, 'show']);
        Route::post('/admin/courses/{id}/skill-config', [SkillConfigController::class, 'upsert']);
        Route::get('/admin/courses/{id}/skills/overview', [CourseSkillsController::class, 'overview']);
        Route::get('/admin/courses/{id}/skills/stats', [CourseSkillsController::class, 'stats']);
    });

    // Chat management (admin + teacher)
    Route::get('/admin/chat/sessions', [AdminChatController::class, 'index']);
    Route::get('/admin/chat/sessions/{id}', [AdminChatController::class, 'show']);
    Route::post('/admin/chat/sessions/{id}/reply', [AdminChatController::class, 'reply']);
});
