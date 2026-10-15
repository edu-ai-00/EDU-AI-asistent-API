<?php

use App\Http\Controllers\Api\AdminActivityController;
use App\Http\Controllers\Api\AdminAuditLogController;
use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AdminAvatarController;
use App\Http\Controllers\Api\AvatarController;
use App\Http\Controllers\Api\AdminChatController;
use App\Http\Controllers\Api\ChatMessageController;
use App\Http\Controllers\Api\ChatSessionController;
use App\Http\Controllers\Api\AdminClassroomController;
use App\Http\Controllers\Api\AdminSchoolController;
use App\Http\Controllers\Api\AdminTeamController;
use App\Http\Controllers\Api\AdminMentorController;
use App\Http\Controllers\Api\AdminNewsController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AdminWorkTimeController;
use App\Http\Controllers\Api\WorkHeartbeatController;
use App\Http\Controllers\Api\AdminUserMergeController;
use App\Http\Controllers\Api\AnswerEvaluationController;
use App\Http\Controllers\Api\AnswerPhotoController;
use App\Http\Controllers\Api\OpenAnswerEvaluationController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BlockStatController;
use App\Http\Controllers\Api\BookmarkController;
use App\Http\Controllers\Api\FsrsProfileController;
use App\Http\Controllers\Api\PracticeCardController;
use App\Http\Controllers\Api\PracticeReviewLogController;
use App\Http\Controllers\Api\ProxyImageController;
use App\Http\Controllers\Api\RagDiagnosticsController;
use App\Http\Controllers\Api\ClassroomMembershipController;
use App\Http\Controllers\Api\CodeController;
use App\Http\Controllers\Api\ConsentController;
use App\Http\Controllers\Api\ConsentManageController;
use App\Http\Controllers\Api\ContentFeedbackController;
use App\Http\Controllers\Api\DebugReportController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\CourseGenerationController;
use App\Http\Controllers\Api\EloBackfillController;
use App\Http\Controllers\Api\EloInteractionController;
use App\Http\Controllers\Api\EloProfileController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\GamificationConfigController;
use App\Http\Controllers\Api\GpfController;
use App\Http\Controllers\Api\LanguageController;
use App\Http\Controllers\Api\MentorController;
use App\Http\Controllers\Api\MiniGameController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\OAuthController;
use App\Http\Controllers\Api\UserAchievementController;
use App\Http\Controllers\Api\QuizAttemptController;
use App\Http\Controllers\Api\QuizFeedbackController;
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
// auth.optional: see OAuth note below — resolves a still-active caller (guest
// or minor) so registerVerified can refuse to write a real name onto a
// minor's session (BR-8FDF37), the same way /guest/claim and /email/verify
// resolve the caller for their own guards.
Route::post('/register-verified', [AuthController::class, 'registerVerified'])
    ->middleware('auth.optional');
Route::post('/login', [AuthController::class, 'login']);

// Guest routes (public)
Route::post('/guest/register', [AuthController::class, 'registerGuest']);

// OAuth social sign-in (public) — id_token exchange, throttled. auth.optional
// resolves a guest caller's still-active token so their progress can be folded
// into the account being signed into (same pattern as /email/verify).
Route::post('/auth/google', [OAuthController::class, 'google'])
    ->middleware(['auth.optional', 'throttle:oauth']);
Route::post('/auth/apple', [OAuthController::class, 'apple'])
    ->middleware(['auth.optional', 'throttle:oauth']);
Route::post('/auth/microsoft', [OAuthController::class, 'microsoft'])
    ->middleware(['auth.optional', 'throttle:oauth']);

// Code resolver (student login code or course PIN) — throttled to deter brute-force.
// auth.optional: see OAuth note above — enables guest → student-account merge.
Route::post('/resolve-code', [CodeController::class, 'resolve'])
    ->middleware(['auth.optional', 'throttle:code-resolve']);

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

// ─────────────────────────────────────────────────────────────────────────────
// Parental consent for minors (public — the parent has no account)
// The confirmation step is intentionally reachable only from the emailed link,
// never from the child's app session.
// ─────────────────────────────────────────────────────────────────────────────
Route::prefix('consent')->group(function () {
    Route::get('/config', [ConsentController::class, 'config']);
    Route::post('/request', [ConsentController::class, 'request'])
        ->middleware('throttle:consent-request');
    Route::post('/confirm', [ConsentController::class, 'confirm'])
        ->middleware('throttle:consent-confirm');

    // '/manage/request-link' must be registered before the '/manage/{token}'
    // wildcard below, or the wildcard route swallows this literal segment.
    Route::post('/manage/request-link', [ConsentManageController::class, 'requestLink'])
        ->middleware('throttle:consent-request');
    Route::get('/manage/{token}', [ConsentManageController::class, 'show'])
        ->middleware('throttle:consent-manage');
    Route::post('/manage/{token}/regenerate', [ConsentManageController::class, 'regenerate'])
        ->middleware('throttle:consent-manage');
    Route::post('/manage/{token}/revoke', [ConsentManageController::class, 'revoke'])
        ->middleware('throttle:consent-manage');
});

// Gamification config (public, cached by app)
Route::get('/gamification/config', [GamificationConfigController::class, 'show']);

// Language catalogue (public — the admin language picker and the app both read
// it before anyone is authenticated). Writes are admin-only, further down.
Route::get('/languages', [LanguageController::class, 'index']);

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

    // Classroom membership — join a class by student code from an existing
    // account (BR-3V2P22). Throttled like /resolve-code: same secret space.
    Route::get('/user/classrooms', [ClassroomMembershipController::class, 'index']);
    Route::post('/user/classrooms/lookup', [ClassroomMembershipController::class, 'lookup'])
        ->middleware('throttle:classroom-join');
    Route::post('/user/classrooms/join', [ClassroomMembershipController::class, 'join'])
        ->middleware('throttle:classroom-join');

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

    // AI evaluation of open answers (BR-FFVVYR) — stateless grading, then
    // persistence for answers given outside quiz mode.
    Route::post('/answers/evaluate', [AnswerEvaluationController::class, 'evaluate'])
        ->middleware('throttle:answer-eval');

    // AI comment for the end-of-quiz feedback popup ("Quiz Feedback v1") —
    // stateless like the evaluator, one call per finished block.
    Route::post('/quiz/feedback', [QuizFeedbackController::class, 'store'])
        ->middleware('throttle:quiz-feedback');
    Route::post('/user/open-answers', [OpenAnswerEvaluationController::class, 'store']);
    Route::get('/user/open-answers', [OpenAnswerEvaluationController::class, 'index']);

    // Photographed answers (BR-P745Q8). Upload is throttled separately from
    // evaluation: a photo costs R2 writes rather than LLM tokens, and a student
    // retaking a blurry shot must not burn their evaluation budget.
    Route::post('/answers/photo', [AnswerPhotoController::class, 'store'])
        ->middleware('throttle:answer-photo');
    Route::get('/answers/photo/{name}', [AnswerPhotoController::class, 'show']);

    // Content feedback
    Route::post('/content-feedback', [ContentFeedbackController::class, 'store']);

    // ELO Profile
    Route::get('/user/elo-profile', [EloProfileController::class, 'show']);
    Route::put('/user/elo-profile', [EloProfileController::class, 'update']);

    // ELO Interactions
    Route::post('/elo/interactions', [EloInteractionController::class, 'store']);
    Route::post('/elo/interactions/batch', [EloInteractionController::class, 'storeBatch']);
    // Per-task reaction times for the Pilot 2 robust gaming threshold (BR-H3TCRE).
    Route::get('/elo/gaming-reference', [EloInteractionController::class, 'gamingReference']);

    // Work-time activity heartbeats (BR-9SAH2R)
    Route::post('/work/heartbeats', [WorkHeartbeatController::class, 'store']);

    // Block Stats — calibrated task difficulty the app pulls (BR-697NCA)
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
        // Mentors offered in the new-chat list; ?course_id= adds the hidden
        // ones bound to that course.
        Route::get('/mentors', [MentorController::class, 'index']);
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

    // Mini games (read-only listing; play spends an attempt)
    Route::get('/mini-games', [MiniGameController::class, 'index']);
    // Declared before /mini-games/{code} so "plays" is never read as a game code.
    Route::patch('/mini-games/plays/{play}/score', [MiniGameController::class, 'submitScore']);
    Route::get('/mini-games/{code}', [MiniGameController::class, 'show']);
    Route::post('/mini-games/{code}/play', [MiniGameController::class, 'play']);

    // Avatar cosmetics - the worn outfit and the inventory behind it.
    // The catalog itself sits outside this group (auth.optional) so the shop
    // renders before a guest token is resolved.
    Route::get('/user/avatar', [AvatarController::class, 'show']);
    Route::put('/user/avatar', [AvatarController::class, 'update']);
    Route::post('/avatar/items/{code}/purchase', [AvatarController::class, 'purchase']);

});

// Avatar catalog (public read - shop is browsable without a token)
Route::middleware('auth.optional')->group(function () {
    Route::get('/avatar/catalog', [AvatarController::class, 'catalog']);
});

// Debug Reports (outside auth:sanctum so reports can be sent even when auth is broken)
Route::middleware('auth.optional')->group(function () {
    Route::post('/debug-reports', [DebugReportController::class, 'store']);
});

// ─────────────────────────────────────────────────────────────────────────────
// AI Course Assistant — phone capture (BR-D4PE8R)
//
// PUBLIC on purpose: the phone that scans the QR code in the admin editor is not
// logged in. The session token in the URL is the whole credential — unguessable,
// stored hashed, expiring in minutes, and only able to append a file to its own
// session. Rate limited per IP on top of that.
// ─────────────────────────────────────────────────────────────────────────────
Route::middleware('throttle:generation-capture')->group(function () {
    Route::get('/generation/capture/{token}', [CourseGenerationController::class, 'captureInfo']);
    Route::post('/generation/capture/{token}/attachments', [CourseGenerationController::class, 'captureUpload']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Admin-only routes (API key or admin role)
// ─────────────────────────────────────────────────────────────────────────────
Route::middleware('admin')->group(function () {
    // Course restore & backfill (admin-only operations)
    Route::post('/courses/{id}/restore', [CourseController::class, 'restore']);
    Route::post('/admin/courses/backfill', [CourseController::class, 'backfill']);

    // Language catalogue management (BR-AYACEK)
    Route::post('/admin/languages', [LanguageController::class, 'store']);
    Route::put('/admin/languages/{code}', [LanguageController::class, 'update']);
    Route::delete('/admin/languages/{code}', [LanguageController::class, 'destroy']);

    // RAG search service diagnostics (mentors with engine=rag)
    Route::get('/admin/rag/health', [RagDiagnosticsController::class, 'show']);

    // Chat mentors (names, AI context, RAG binding, course visibility)
    Route::get('/admin/mentors/rag-entities', [AdminMentorController::class, 'ragEntities']);
    Route::get('/admin/mentors', [AdminMentorController::class, 'index']);
    Route::post('/admin/mentors', [AdminMentorController::class, 'store']);
    Route::put('/admin/mentors/{id}', [AdminMentorController::class, 'update']);
    Route::delete('/admin/mentors/{id}', [AdminMentorController::class, 'destroy']);

    // Pupils' chats (BR-4KPUJQ). Teaching a pupil's class is no reason to read
    // their conversations — the client wants only a mentor to, and no teacher
    // can be one yet. So these left the admin:admin,teacher group below, where
    // a teacher saw every chat of their classes' pupils.
    Route::get('/admin/chat/sessions', [AdminChatController::class, 'index']);
    Route::get('/admin/chat/sessions/{id}', [AdminChatController::class, 'show']);
    Route::post('/admin/chat/sessions/{id}/reply', [AdminChatController::class, 'reply']);

    // Content feedback management
    Route::get('/admin/content-feedback', [ContentFeedbackController::class, 'index']);
    Route::get('/admin/content-feedback/{id}', [ContentFeedbackController::class, 'show']);

    // Debug reports management
    Route::get('/admin/debug-reports', [DebugReportController::class, 'index']);
    Route::get('/admin/debug-reports/{id}', [DebugReportController::class, 'show']);

    // ELO management
    Route::get('/admin/elo/profiles', [EloProfileController::class, 'adminIndex']);
    // The two per-user ELO reads live in the teacher group below (BR-J9D2J9).
    Route::get('/admin/elo/interactions/course/{courseId}', [EloInteractionController::class, 'adminByCourse']);
    Route::get('/admin/elo/interactions/course/{courseId}/export', [EloInteractionController::class, 'exportCsv']);
    Route::get('/admin/blocks/stats', [BlockStatController::class, 'adminIndex']);

    // ELO backfill (admin-only)
    Route::get('/admin/elo/backfill/preview/{courseId}', [EloBackfillController::class, 'preview']);
    Route::post('/admin/elo/backfill/run/{courseId}', [EloBackfillController::class, 'run']);
    Route::post('/admin/elo/backfill/timestamps/{courseId}', [EloBackfillController::class, 'runTimestampBackfill']);
    // Task difficulty recalibration, paged like the timestamp backfill (BR-697NCA)
    Route::post('/admin/elo/recalibrate/{courseId}', [EloBackfillController::class, 'recalibrate']);

    // AI Course Assistant — wizard in the course editor (BR-D4PE8R)
    Route::post('/admin/generation/sessions', [CourseGenerationController::class, 'store']);
    Route::get('/admin/generation/sessions/{token}', [CourseGenerationController::class, 'show']);
    Route::delete('/admin/generation/sessions/{token}', [CourseGenerationController::class, 'destroy']);
    Route::post('/admin/generation/sessions/{token}/attachments', [CourseGenerationController::class, 'upload']);
    Route::delete('/admin/generation/sessions/{token}/attachments/{index}', [CourseGenerationController::class, 'destroyAttachment']);
    Route::get('/admin/generation/sessions/{token}/stream', [CourseGenerationController::class, 'stream']);

    // Asset management
    Route::get('/admin/assets', [AssetController::class, 'index']);
    Route::post('/admin/assets/upload', [AssetController::class, 'store']);
    Route::delete('/admin/assets/{id}', [AssetController::class, 'destroy']);

    // Avatar catalog management (artwork upload + metadata)
    Route::get('/admin/avatar-items', [AdminAvatarController::class, 'index']);
    Route::post('/admin/avatar-items/upload', [AdminAvatarController::class, 'store']);
    Route::put('/admin/avatar-items/{id}', [AdminAvatarController::class, 'update']);
    Route::delete('/admin/avatar-items/{id}', [AdminAvatarController::class, 'destroy']);

    // Team management (admin-only)
    Route::get('/admin/team', [AdminTeamController::class, 'index']);
    Route::post('/admin/team', [AdminTeamController::class, 'store']);
    Route::put('/admin/team/{id}', [AdminTeamController::class, 'update']);
    Route::delete('/admin/team/{id}', [AdminTeamController::class, 'destroy']);
    // Resend a pending or expired invite (BR-XW6CWV)
    Route::post('/admin/team/{id}/resend', [AdminTeamController::class, 'resend'])->whereNumber('id');

    // Account deactivation (admin-only, BR-Q3ZJ6Q). One pair for students and
    // team members alike — the user detail and the team list both call it.
    Route::post('/admin/users/{id}/deactivate', [AdminUserController::class, 'deactivate'])->whereNumber('id');
    Route::post('/admin/users/{id}/reactivate', [AdminUserController::class, 'reactivate'])->whereNumber('id');
    // Deleting a student and their history is admin-only too (BR-FXWJ8D).
    Route::delete('/admin/users/{id}', [AdminUserController::class, 'destroy'])->whereNumber('id');

    // Schools as organisational units (admin-only, BR-8QAWKP). Teachers are
    // assigned to schools here and nowhere else — the client asked that this
    // stay an admin decision, so these deliberately sit outside the
    // admin:admin,teacher group below.
    Route::get('/admin/schools', [AdminSchoolController::class, 'index']);
    Route::post('/admin/schools', [AdminSchoolController::class, 'store']);
    Route::get('/admin/schools/{id}', [AdminSchoolController::class, 'show']);
    Route::put('/admin/schools/{id}', [AdminSchoolController::class, 'update']);
    Route::delete('/admin/schools/{id}', [AdminSchoolController::class, 'destroy']);
    Route::put('/admin/schools/{id}/classrooms', [AdminSchoolController::class, 'syncClassrooms']);

    // Deleting a class erases the placeholder pupils minted for it; the
    // client keeps that with the admin, not the class's teacher (BR-3JZWEY).
    // The rest of classroom management stays in admin:admin,teacher below.
    Route::delete('/admin/classrooms/{id}', [AdminClassroomController::class, 'destroy']);

    // Unified AUM audit log (BR-BNNASY). Admin-only until it is decided what
    // a teacher may see of their own scope.
    Route::get('/admin/audit-logs', [AdminAuditLogController::class, 'index']);
    Route::get('/admin/audit-logs/export', [AdminAuditLogController::class, 'export']);

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
    Route::get('/admin/users/{userId}/work-time', [AdminWorkTimeController::class, 'show'])->middleware('teacher.student');

    // Per-student daily activity overview + CSV export (BR-AT7X2T)
    Route::get('/admin/activity', [AdminActivityController::class, 'index']);
    Route::get('/admin/activity/export', [AdminActivityController::class, 'export']);
    Route::get('/admin/users/{id}', [AdminUserController::class, 'show']);
    // The student detail reads both of these on open — the header's ELO summary
    // and the Skills tab — so admin-only would log the teacher out (BR-J9D2J9).
    Route::get('/admin/elo/profile/{userId}', [EloProfileController::class, 'adminByUser'])->middleware('teacher.student');
    Route::get('/admin/elo/interactions/user/{userId}', [EloInteractionController::class, 'adminByUser'])->middleware('teacher.student');
    Route::put('/admin/users/{id}', [AdminUserController::class, 'update']);

    // Classroom management
    Route::post('/admin/classrooms', [AdminClassroomController::class, 'store']);
    Route::get('/admin/classrooms', [AdminClassroomController::class, 'index']);
    Route::get('/admin/classrooms/{id}', [AdminClassroomController::class, 'show']);
    Route::put('/admin/classrooms/{id}', [AdminClassroomController::class, 'update']);
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
    Route::get('/admin/progress/user/{userId}', [UserProgressController::class, 'adminByUser'])->middleware('teacher.student');

    // Quiz attempts management
    Route::get('/admin/quiz-attempts/{courseId}', [QuizAttemptController::class, 'adminByCourse'])->middleware('teacher.course:courseId');
    Route::get('/admin/quiz-attempts/user/{userId}', [QuizAttemptController::class, 'adminByUser'])->middleware('teacher.student');

    // Open (free-text) answers from lessons/exercises — AI-graded (BR-FFVVYR)
    Route::get('/admin/open-answers/{courseId}', [OpenAnswerEvaluationController::class, 'adminByCourse'])->middleware('teacher.course:courseId');
    Route::get('/admin/open-answers/user/{userId}', [OpenAnswerEvaluationController::class, 'adminByUser'])->middleware('teacher.student');
    // Photographed answer, streamed through the API — the R2 object has no
    // public URL because these are pictures of children's schoolwork.
    Route::get('/admin/open-answers/{id}/photo', [AnswerPhotoController::class, 'adminShow'])
        ->whereNumber('id');

    // Course skill config (admin read + write) + skills overview/stats
    Route::middleware('teacher.course')->group(function () {
        Route::get('/admin/courses/{id}/skill-config', [SkillConfigController::class, 'show']);
        Route::post('/admin/courses/{id}/skill-config', [SkillConfigController::class, 'upsert']);
        Route::get('/admin/courses/{id}/skills/overview', [CourseSkillsController::class, 'overview']);
        Route::get('/admin/courses/{id}/skills/stats', [CourseSkillsController::class, 'stats']);
    });
});
