<?php

return [
    /*
    | How long an AUM audit row is kept (BR-BNNASY). Pruned daily by
    | `model:prune --model=App\Models\AuditLog`. 0 keeps rows forever.
    |
    | Consent events are mirrored into the unified log but their evidentiary
    | copy stays in consent_audit_logs, which is kept forever by design
    | (BR-8FDF37 decision 9) — so this retention never erases proof of a
    | consent or its revocation.
    */
    'retention_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 365),

    // The most rows one export may contain. Past this the export is refused
    // with a request to narrow the filter, rather than streaming the whole
    // log out in one go.
    'export_max_rows' => (int) env('AUDIT_LOG_EXPORT_MAX_ROWS', 10000),
];
