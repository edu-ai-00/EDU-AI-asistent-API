<?php

// Pilot 2 ELO — server-side task difficulty calibration (BR-697NCA).
return [
    // Master switch for the task half of the engine on the server. Off: every
    // answer is still logged, but none moves a block's difficulty (D) or its
    // update count (m), GET /api/blocks/stats publishes no calibrated D (the
    // app keeps the course's own elo_vector), and the nightly recalibration
    // does not run. `php artisan elo:recalibrate` and the admin button work
    // either way — rollout recalibrates first, checks the numbers, then turns
    // this on.
    'task_calibration' => (bool) env('ELO_TASK_CALIBRATION', false),
];
