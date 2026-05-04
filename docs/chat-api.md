# Chat API Documentation

> EDU-AI Chat Module — AI chat + teacher messaging

**Base URL:** `https://app-api-stage.edu-ai.eu/api`

---

## Authentication

All endpoints require `Authorization: Bearer <sanctum_token>`.

- **Student endpoints** (`/api/chat/*`) — any authenticated user
- **Admin/Teacher endpoints** (`/api/admin/chat/*`) — requires `admin` or `teacher` role (via `admin:admin,teacher` middleware)

---

## Student Endpoints

### 1. List Sessions

```
GET /api/chat/sessions
GET /api/chat/sessions?since=2026-03-25T12:00:00Z
```

Returns the authenticated user's chat sessions. `since` param filters by `updated_at > since` for incremental sync.

**Response** `200`:

```json
{
  "data": [
    {
      "id": 1,
      "title": "Zlomky a procenta",
      "persona": "ai_teacher",
      "last_message_at": "2026-03-25T14:30:00+00:00",
      "created_at": "2026-03-25T10:00:00+00:00",
      "updated_at": "2026-03-25T14:30:00+00:00"
    }
  ]
}
```

---

### 2. Create Session

```
POST /api/chat/sessions
Content-Type: application/json

{
  "persona": "ai_teacher",
  "title": ""
}
```

| Field | Type | Rules |
|---|---|---|
| `persona` | string | **Required.** One of: `ai_teacher`, `math_mentor`, `study_coach`, `language_mentor` |
| `title` | string | Optional, max 255. Empty string or null allowed. |

**Response** `201`:

```json
{
  "data": {
    "id": 1,
    "title": "",
    "persona": "ai_teacher",
    "last_message_at": "2026-03-25T10:00:00+00:00",
    "created_at": "2026-03-25T10:00:00+00:00",
    "updated_at": "2026-03-25T10:00:00+00:00"
  }
}
```

---

### 3. Delete Session

```
DELETE /api/chat/sessions/{id}
```

Deletes the session and all its messages (cascade). Must be session owner.

**Response** `200`:

```json
{ "message": "Session deleted" }
```

---

### 4. Get Session Messages

```
GET /api/chat/sessions/{id}/messages
GET /api/chat/sessions/{id}/messages?since=2026-03-25T12:00:00Z
```

Returns messages ordered by `created_at ASC`. `since` filters by `updated_at > since`.

**Response** `200`:

```json
{
  "data": [
    {
      "id": 42,
      "role": "user",
      "content": "Ahoj, jak se učit zlomky?",
      "message_type": "text",
      "metadata": null,
      "feedback_type": null,
      "feedback_detail": null,
      "created_at": "2026-03-25T14:30:00+00:00",
      "updated_at": "2026-03-25T14:30:00+00:00"
    },
    {
      "id": 43,
      "role": "assistant",
      "content": "Ahoj! Zlomky jsou zajímavé...",
      "message_type": "text",
      "metadata": null,
      "feedback_type": null,
      "feedback_detail": null,
      "created_at": "2026-03-25T14:30:01+00:00",
      "updated_at": "2026-03-25T14:31:00+00:00"
    }
  ]
}
```

**Note:** Teacher replies appear as `role: "assistant"` with `metadata.sent_by: "teacher"`. The app can check `metadata.sent_by` to distinguish AI responses from teacher messages.

---

### 5. Send Message (Two-Step Flow — Step 1)

```
POST /api/chat/sessions/{id}/messages
Content-Type: application/json

{
  "content": "Ahoj, jak se učit zlomky?"
}
```

| Field | Type | Rules |
|---|---|---|
| `content` | string | **Required.** Max 4000 characters. |

**Rate limit:** 20 messages per minute per user. Returns `429` if exceeded.

Creates the user message + an empty assistant placeholder. Does NOT start AI generation.

**Response** `201`:

```json
{
  "data": {
    "user_message": {
      "id": 42,
      "role": "user",
      "content": "Ahoj, jak se učit zlomky?",
      "message_type": "text",
      "metadata": null,
      "feedback_type": null,
      "feedback_detail": null,
      "created_at": "2026-03-25T14:30:00+00:00",
      "updated_at": "2026-03-25T14:30:00+00:00"
    },
    "assistant_message": {
      "id": 43,
      "role": "assistant",
      "content": "",
      "message_type": "text",
      "metadata": null,
      "feedback_type": null,
      "feedback_detail": null,
      "created_at": "2026-03-25T14:30:01+00:00",
      "updated_at": "2026-03-25T14:30:01+00:00"
    }
  }
}
```

---

### 6. Stream AI Response (Two-Step Flow — Step 2)

```
GET /api/chat/sessions/{id}/stream?message_id={assistantMessageId}
Accept: text/event-stream
```

Opens an SSE connection. The server calls OpenRouter, streams tokens to the client, then saves the full response.

**SSE events:**

```
data: {"token":"Ahoj"}

data: {"token":"! Zlomky"}

data: {"token":" jsou zajímavé..."}

data: [DONE]
```

| Event | Format |
|---|---|
| Token | `data: {"token": "<text>"}\n\n` |
| Error | `data: {"error": "<message>"}\n\n` |
| End | `data: [DONE]\n\n` |

**Reconnection:** If `message_id` already has content (connection dropped mid-stream), the server returns the existing content as a single token followed by `[DONE]`.

**Response headers:**

```
Content-Type: text/event-stream
Cache-Control: no-cache
Connection: keep-alive
X-Accel-Buffering: no
```

---

### 7. Pull All Messages (Cross-Session Sync)

```
GET /api/chat/messages?since=2026-03-25T12:00:00Z
```

Returns messages from **all sessions** belonging to the user where `updated_at > since`. Includes `chat_session_id` for session association.

**Response** `200`:

```json
{
  "data": [
    {
      "id": 43,
      "chat_session_id": 1,
      "role": "assistant",
      "content": "...",
      "message_type": "text",
      "feedback_type": null,
      "created_at": "2026-03-25T14:30:01+00:00",
      "updated_at": "2026-03-25T14:31:00+00:00"
    }
  ]
}
```

---

### 8. Update Feedback

```
PUT /api/chat/messages/{id}/feedback
Content-Type: application/json

{
  "feedback_type": "dislike",
  "feedback_detail": "Informace byla nepřesná"
}
```

| Field | Type | Rules |
|---|---|---|
| `feedback_type` | string\|null | `"like"`, `"dislike"`, or `null` (to clear) |
| `feedback_detail` | string\|null | Max 2000. Free text for dislike reports. |

**Response** `200`:

```json
{
  "data": {
    "id": 43,
    "feedback_type": "dislike",
    "feedback_detail": "Informace byla nepřesná",
    "updated_at": "2026-03-25T15:00:00+00:00"
  }
}
```

---

## Admin / Teacher Endpoints

These endpoints are accessible to users with `admin` or `teacher` role. Teachers can only see and interact with chat sessions from students in their classrooms.

### 9. List All Chat Sessions

```
GET /api/admin/chat/sessions
GET /api/admin/chat/sessions?user_id=5
GET /api/admin/chat/sessions?persona=ai_teacher
GET /api/admin/chat/sessions?classroom_id=3
GET /api/admin/chat/sessions?search=zlomky
GET /api/admin/chat/sessions?page=2
```

| Filter | Type | Description |
|---|---|---|
| `user_id` | integer | Filter by specific student |
| `persona` | string | Filter by persona type |
| `classroom_id` | integer | Filter by classroom (all students in that classroom) |
| `search` | string | Search in session title |
| `page` | integer | Pagination (50 per page) |

**Access control:**
- **Admin:** sees all sessions
- **Teacher:** sees only sessions from students in their classrooms

**Response** `200`:

```json
{
  "data": [
    {
      "id": 1,
      "user": {
        "id": 5,
        "name": "Jan Novák",
        "email": "jan@example.com"
      },
      "title": "Zlomky a procenta",
      "persona": "ai_teacher",
      "message_count": 12,
      "last_message_at": "2026-03-25T14:30:00+00:00",
      "created_at": "2026-03-25T10:00:00+00:00",
      "updated_at": "2026-03-25T14:30:00+00:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 50,
    "total": 142
  }
}
```

---

### 10. Show Chat Session Detail

```
GET /api/admin/chat/sessions/{id}
```

Returns the session with **all messages** included. Teachers can only view sessions from their classroom students.

**Response** `200`:

```json
{
  "data": {
    "id": 1,
    "user": {
      "id": 5,
      "name": "Jan Novák",
      "email": "jan@example.com"
    },
    "title": "Zlomky a procenta",
    "persona": "ai_teacher",
    "last_message_at": "2026-03-25T14:30:00+00:00",
    "created_at": "2026-03-25T10:00:00+00:00",
    "updated_at": "2026-03-25T14:30:00+00:00",
    "messages": [
      {
        "id": 42,
        "role": "user",
        "content": "Ahoj, jak se učit zlomky?",
        "message_type": "text",
        "metadata": null,
        "feedback_type": null,
        "feedback_detail": null,
        "created_at": "2026-03-25T14:30:00+00:00",
        "updated_at": "2026-03-25T14:30:00+00:00"
      },
      {
        "id": 43,
        "role": "assistant",
        "content": "Ahoj! Zlomky jsou...",
        "message_type": "text",
        "metadata": null,
        "feedback_type": "like",
        "feedback_detail": null,
        "created_at": "2026-03-25T14:30:01+00:00",
        "updated_at": "2026-03-25T14:31:00+00:00"
      }
    ]
  }
}
```

---

### 11. Reply to Student Chat (Teacher Message)

```
POST /api/admin/chat/sessions/{id}/reply
Content-Type: application/json

{
  "content": "Správně! Zkus ještě tento příklad: 3/4 + 1/2 = ?"
}
```

| Field | Type | Rules |
|---|---|---|
| `content` | string | **Required.** Max 4000 characters. |

Sends a teacher message into the student's chat. The message appears as `role: "assistant"` so it shows on the student's side like a normal response. The `metadata` field identifies it as a teacher message.

**Access control:**
- **Admin:** can reply to any session
- **Teacher:** can only reply to sessions from students in their classrooms

**Response** `201`:

```json
{
  "data": {
    "id": 44,
    "role": "assistant",
    "content": "Správně! Zkus ještě tento příklad...",
    "message_type": "text",
    "metadata": {
      "sent_by": "teacher",
      "teacher_id": 10,
      "teacher_name": "Mgr. Petra Svobodová"
    },
    "created_at": "2026-03-25T15:00:00+00:00",
    "updated_at": "2026-03-25T15:00:00+00:00"
  }
}
```

**How the Flutter app can detect teacher messages:** Check `metadata.sent_by == "teacher"`. This lets the app render teacher messages differently (e.g., with the teacher's name/avatar instead of AI icon).

---

## Personas

| Persona ID | Name | Description |
|---|---|---|
| `ai_teacher` | AI Učitel | General teaching assistant, all subjects |
| `math_mentor` | Matematický Mentor | Step-by-step math, LaTeX formulas |
| `study_coach` | Studijní Kouč | Study techniques, planning, motivation (no direct homework answers) |
| `language_mentor` | Jazykový Mentor | Language learning, gentle grammar correction |

---

## System Prompt (Server-Side)

The server builds the system prompt automatically. It includes:

- Student's name, level, XP (from `user_stats`)
- List of enrolled course names (from `user_courses` + `courses`)
- Persona-specific instructions
- Rules: Czech language, encouraging tone, Markdown formatting, concise responses

The app does **not** send system prompts — the server owns all prompt engineering.

---

## Error Responses

```json
// Validation (422)
{
  "message": "The content field is required.",
  "errors": { "content": ["The content field is required."] }
}

// Unauthenticated (401)
{ "message": "Unauthenticated." }

// Not found (404)
{ "message": "No query results for model [App\\Models\\ChatSession]." }

// Forbidden (403)
{ "message": "Access denied." }

// Rate limit (429)
{ "message": "Too many messages. Please wait.", "retry_after": 30 }
```

---

## Endpoints Summary

### Student (auth:sanctum)

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/chat/sessions` | List my sessions (`?since=`) |
| POST | `/api/chat/sessions` | Create session |
| DELETE | `/api/chat/sessions/{id}` | Delete session |
| GET | `/api/chat/sessions/{id}/messages` | Get messages (`?since=`) |
| POST | `/api/chat/sessions/{id}/messages` | Send message (rate limited) |
| GET | `/api/chat/sessions/{id}/stream` | SSE stream (`?message_id=`) |
| GET | `/api/chat/messages` | Cross-session sync (`?since=`) |
| PUT | `/api/chat/messages/{id}/feedback` | Update feedback |

### Admin / Teacher (admin:admin,teacher)

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/admin/chat/sessions` | List all sessions (paginated, filterable) |
| GET | `/api/admin/chat/sessions/{id}` | Show session detail with all messages |
| POST | `/api/admin/chat/sessions/{id}/reply` | Send teacher reply into student's chat |

---

## Environment Variables

| Variable | Default | Description |
|---|---|---|
| `AI_API_KEY` | — | OpenRouter API key |
| `AI_API_URL` | `https://openrouter.ai/api/v1` | OpenRouter base URL |
| `AI_MODEL` | `openai/gpt-4o` | Model ID |
| `AI_MAX_TOKENS` | `2048` | Max response tokens |
| `AI_MAX_CONTEXT` | `128000` | Max context window for truncation |
