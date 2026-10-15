<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use App\Models\Mentor;
use App\Services\RagSearchException;
use App\Services\RagSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin CRUD for chat mentors — what used to be PHP constants.
 *
 * Slugs are the join key to chat_sessions.persona, so they are set once on
 * create and never editable afterwards; retiring a mentor is is_active=false,
 * not a delete, so its past conversations keep answering correctly.
 */
class AdminMentorController extends Controller
{
    /**
     * GET /api/admin/mentors
     */
    public function index(): JsonResponse
    {
        $mentors = Mentor::with('course:id,name')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $sessionCounts = ChatSession::selectRaw('persona, count(*) as total')
            ->groupBy('persona')
            ->pluck('total', 'persona');

        return response()->json([
            'data' => $mentors->map(fn (Mentor $m) => $this->format($m, (int) ($sessionCounts[$m->slug] ?? 0))),
        ]);
    }

    /**
     * POST /api/admin/mentors
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules($request, null));

        // refresh() so the response carries the column defaults (visibility,
        // is_active, sort_order) the request left out.
        $mentor = Mentor::create($this->normalise($validated))->refresh();

        return response()->json(['data' => $this->format($mentor, 0)], 201);
    }

    /**
     * PUT /api/admin/mentors/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $mentor = Mentor::findOrFail($id);

        $validated = $request->validate($this->rules($request, $mentor));

        // Renaming a slug would orphan every chat_sessions row that stores it.
        unset($validated['slug']);

        $mentor->fill($this->normalise($validated, $mentor))->save();

        return response()->json(['data' => $this->format($mentor->fresh(), null)]);
    }

    /**
     * DELETE /api/admin/mentors/{id}
     *
     * Refused while conversations still reference the mentor — deactivate it
     * instead, which hides it from the pickers but keeps history answerable.
     */
    public function destroy(int $id): JsonResponse
    {
        $mentor = Mentor::findOrFail($id);

        $sessions = ChatSession::where('persona', $mentor->slug)->count();
        if ($sessions > 0) {
            return response()->json([
                'message' => "Mentor má {$sessions} konverzací — místo smazání ho deaktivuj.",
            ], 409);
        }

        $mentor->delete();

        return response()->json(['data' => true]);
    }

    /**
     * GET /api/admin/mentors/rag-entities
     *
     * The legal entities the RAG service offers, for the picker on a RAG
     * mentor. Never fatal: on an outage the form falls back to free text.
     */
    public function ragEntities(RagSearchService $rag): JsonResponse
    {
        try {
            return response()->json([
                'data' => $rag->entities(),
                'meta' => ['default' => (string) config('rag.legal_entity')],
            ]);
        } catch (RagSearchException $e) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'default' => (string) config('rag.legal_entity'),
                    'error' => $e->reason,
                    'error_detail' => $e->getMessage(),
                ],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(Request $request, ?Mentor $mentor): array
    {
        $required = $mentor === null ? 'required' : 'sometimes';
        $visibility = (string) $request->input('visibility', $mentor?->visibility ?? Mentor::VISIBILITY_PUBLIC);

        // The course the mentor would end up bound to: what the request sends,
        // or what it is already bound to when the field is left out. A partial
        // update of an already-hidden mentor must not have to resend it.
        $keepsCourse = $request->has('course_id')
            ? $request->input('course_id') !== null
            : $mentor?->course_id !== null;

        return [
            'slug' => [
                $mentor === null ? 'required' : 'sometimes',
                'string',
                'max:50',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('mentors', 'slug'),
            ],
            'name' => [$required, 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'emoji' => ['nullable', 'string', 'max:16'],
            'avatar_url' => ['nullable', 'url', 'max:500'],
            'engine' => [$required, Rule::in(Mentor::ENGINES)],
            'ai_context' => ['nullable', 'string', 'max:50000'],
            'rag_legal_entity' => ['nullable', 'string', 'max:100'],
            // RAG document-domain codes, e.g. SECONDARY_ADMISSIONS (BR-DAB49K).
            'rag_domains' => ['nullable', 'array', 'max:20'],
            'rag_domains.*' => ['nullable', 'string', 'max:100'],
            'visibility' => ['sometimes', Rule::in(Mentor::VISIBILITIES)],
            // A hidden mentor is reachable only through its course, so it has
            // to have one — otherwise nobody could ever open it.
            'course_id' => [
                Rule::requiredIf($visibility === Mentor::VISIBILITY_HIDDEN && ! $keepsCourse),
                'nullable',
                'integer',
                Rule::exists('courses', 'id'),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * Drop the fields the chosen engine does not use, so a mentor flipped from
     * LLM to RAG stops carrying a stale prompt (and vice versa).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data, ?Mentor $mentor = null): array
    {
        $engine = $data['engine'] ?? $mentor?->engine ?? Mentor::ENGINE_LLM;

        if ($engine === Mentor::ENGINE_RAG) {
            $data['ai_context'] = null;
        } else {
            $data['rag_legal_entity'] = null;
            $data['rag_domains'] = null;
        }

        // Blanks and repeats come from the comma-separated admin field; an
        // empty list is stored as null so the chat sends no `domains` key.
        if (array_key_exists('rag_domains', $data) && $data['rag_domains'] !== null) {
            $domains = array_values(array_unique(array_filter(
                array_map(fn ($d) => trim((string) $d), $data['rag_domains']),
                fn (string $d) => $d !== '',
            )));
            $data['rag_domains'] = $domains === [] ? null : $domains;
        }

        return $data;
    }

    /**
     * @param  int|null  $sessionCount  null = not counted for this response
     */
    private function format(Mentor $mentor, ?int $sessionCount): array
    {
        return [
            'id' => $mentor->id,
            'slug' => $mentor->slug,
            'name' => $mentor->name,
            'subtitle' => $mentor->subtitle,
            'description' => $mentor->description,
            'emoji' => $mentor->emoji,
            'avatar_url' => $mentor->avatar_url,
            'engine' => $mentor->engine,
            'ai_context' => $mentor->ai_context,
            'rag_legal_entity' => $mentor->rag_legal_entity,
            'rag_legal_entity_effective' => $mentor->isRag() ? $mentor->legalEntity() : null,
            'rag_domains' => $mentor->ragDomains(),
            'visibility' => $mentor->visibility,
            'course_id' => $mentor->course_id,
            'course_name' => $mentor->relationLoaded('course') ? $mentor->course?->name : null,
            'is_active' => $mentor->is_active,
            'sort_order' => $mentor->sort_order,
            'session_count' => $sessionCount,
            'created_at' => $mentor->created_at?->toIso8601String(),
            'updated_at' => $mentor->updated_at?->toIso8601String(),
        ];
    }
}
