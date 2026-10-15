<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Services\ClassroomJoinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A student's own classroom membership (BR-3V2P22).
 *
 *   GET  /api/user/classrooms         → the classes the caller is in
 *   POST /api/user/classrooms/lookup  → what a student code would join (dry run)
 *   POST /api/user/classrooms/join    → join it
 *
 * All three sit behind auth:sanctum; lookup/join are throttled
 * (classroom-join) the same way /resolve-code is, since a code is a
 * six-character secret and this is another place to guess it.
 * ClassroomJoinException renders itself as {message} with its status.
 *
 * `code` is either a six-character student code or, since BR-TT6TD7, a
 * four-character class code the teacher set on the class. The length
 * decides which — the two never overlap, and the app sends both to the
 * same two endpoints. A class code has no roster name, so lookup answers
 * `student_name: null` for it.
 */
class ClassroomMembershipController extends Controller
{
    public function __construct(private readonly ClassroomJoinService $join) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->classroomList($request->user()->classrooms()->orderBy('classroom_student.id')->get()),
        ]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $code = $this->validatedCode($request);

        if ($this->isClassCode($code)) {
            $classroom = $this->join->resolveClassCode($request->user(), $code);

            return response()->json([
                'data' => [
                    'classroom' => $this->classroomSummary($classroom),
                    'student_name' => null,
                ],
            ]);
        }

        [$placeholder, $classroom] = $this->join->resolve($request->user(), $code);

        return response()->json([
            'data' => [
                'classroom' => $this->classroomSummary($classroom),
                // The name the teacher typed on the roster — lets the
                // confirmation dialog say who the teacher thinks this is.
                'student_name' => $placeholder->name,
            ],
        ]);
    }

    public function join(Request $request): JsonResponse
    {
        $code = $this->validatedCode($request);

        $classroom = $this->isClassCode($code)
            ? $this->join->joinByClassCode($request->user(), $code)
            : $this->join->join($request->user(), $code);

        $user = $request->user()->fresh();

        return response()->json([
            'data' => [
                'classroom' => $this->classroomSummary($classroom),
                'classrooms' => $this->classroomList($user->classrooms()->orderBy('classroom_student.id')->get()),
            ],
        ]);
    }

    private function validatedCode(Request $request): string
    {
        $validated = $request->validate([
            // Four (class code) or six (student code) ASCII letters/digits.
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9]{4}([A-Za-z0-9]{2})?$/'],
        ]);

        return strtoupper(trim($validated['code']));
    }

    private function isClassCode(string $code): bool
    {
        return (bool) preg_match(Classroom::JOIN_CODE_REGEX, $code);
    }

    /** @return array{id: int, name: string} */
    private function classroomSummary(Classroom $classroom): array
    {
        return ['id' => $classroom->id, 'name' => $classroom->name];
    }

    /** @return array<int, array{id: int, name: string}> */
    private function classroomList(iterable $classrooms): array
    {
        return collect($classrooms)->map(fn (Classroom $c) => $this->classroomSummary($c))->values()->all();
    }
}
