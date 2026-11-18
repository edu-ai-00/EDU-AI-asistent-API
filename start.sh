#!/bin/bash
set -e

# Sentry release = the deployed commit. Exported before anything else starts
# so the web server, the scheduler and the queue worker below all tag their
# events with the same release (config/sentry.php reads SENTRY_RELEASE).
if [ -z "$SENTRY_RELEASE" ] && [ -n "$RAILWAY_GIT_COMMIT_SHA" ]; then
    export SENTRY_RELEASE="$RAILWAY_GIT_COMMIT_SHA"
fi

php artisan config:clear
php artisan route:clear
php artisan migrate --force

# Mini game rows are reference data the client resolves engines against, so an
# environment with an empty mini_games table shows an empty listing however
# well the app is built. MiniGameSeeder is keyed on `code` (updateOrCreate), so
# this reconciles metadata on every deploy instead of duplicating rows.
# Only this seeder — a full db:seed would create demo courses.
php artisan db:seed --class=MiniGameSeeder --force

# Same story for the avatar catalog: without rows the shop is empty and the
# default avatar has nothing to draw. Keyed on `code`, and artwork is only
# pushed to R2 for items that have no row yet, so a steady-state deploy makes
# no storage calls and never clobbers an admin's replacement upload.
php artisan db:seed --class=AvatarItemSeeder --force

# Notification types and their system rules (NOTIF-1 … 10). Without them no
# rule fires and the admin's Notifikace page is empty. Create-if-missing, keyed
# on `key`: a deploy adds what is new and never puts back a text, limit or
# channel the admin has since edited.
php artisan db:seed --class=NotificationCatalogSeeder --force

# Runs $* in a restart loop, forever, in the background. Both the scheduler
# and the queue worker below are meant to run for the container's whole
# lifetime; without something supervising them, a crash (DB blip, OOM) just
# ends the process silently — php artisan serve keeps answering requests and
# the container looks perfectly healthy while cron (including the consent
# purge that backs our 30-day erasure promise) has stopped running. `set +e`
# inside the subshell is required, not decorative: without it, the `set -e`
# at the top of this script would kill the loop the moment the supervised
# command first exits non-zero — the one case this wrapper exists to survive.
run_supervised() {
    (
        set +e
        while true; do
            "$@"
            echo "[supervisor] '$*' exited with code $? — restarting in 2s" >&2
            sleep 2
        done
    ) &
}

# Scheduler — without this nothing scheduled in routes/console.php ever runs,
# including the consent purge that backs our 30-day erasure promise.
run_supervised php artisan schedule:work

# Queue worker. Notifications are queued work — rule evaluation, delayed
# sends, broadcasts, every push — so without a worker no rule ever fires and
# nothing is pushed, while the request that emitted the event looks fine.
# `notifications` is drained before `default`. --max-time ends the worker hourly and
# run_supervised starts a fresh one, so a slow memory leak cannot build up.
run_supervised php artisan queue:work --queue=notifications,default --sleep=3 --tries=3 --max-time=3600

# `php artisan serve` runs PHP's built-in server, which handles ONE request at a
# time unless PHP_CLI_SERVER_WORKERS says otherwise — and Laravel only honours
# that variable when --no-reload is passed (ServeCommand warns about exactly
# this). With a single worker the AI Course Assistant's SSE stream holds the
# only worker for the whole generation, so every other caller — the Flutter app
# included — waits minutes for a response.
#
# --no-reload only disables the file-watcher restart, which is meaningless in a
# container where the code never changes.
#
# Lower PHP_CLI_SERVER_WORKERS if the container turns out to be memory-tight;
# each worker is a full PHP process.
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
php artisan serve --host=0.0.0.0 --port=${PORT:-8080} --no-reload
