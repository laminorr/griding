#!/bin/bash
# Database queue worker supervisor loop.
#
# Replaces the cron line that ran `queue:work ... --max-time=3540` under flock
# once a minute: when max-time elapsed the worker exited and nothing drained
# the queue until the next cron minute (up to a ~60s gap every hour). This
# loop restarts the worker 1s after it exits for ANY reason — max-time,
# --memory, a crash, or `php artisan queue:restart` on deploy (the new worker
# boots the new code).
#
# Safe to start from cron every minute: a non-blocking flock on
# storage/framework/queue-worker.lock makes a second copy exit at once.
# Every (re)start is logged to storage/logs/queue-worker.log.

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT" || exit 1

# Prefer ea-php83 (cPanel guarantees this stays at PHP 8.3).
# Fall back to ea-php84, then generic php in PATH.
if [ -n "${QUEUE_WORKER_PHP:-}" ]; then
    PHP_BIN="$QUEUE_WORKER_PHP"   # test hook
elif [ -x "/usr/local/bin/ea-php83" ]; then
    PHP_BIN="/usr/local/bin/ea-php83"
elif [ -x "/usr/local/bin/ea-php84" ]; then
    PHP_BIN="/usr/local/bin/ea-php84"
else
    PHP_BIN="$(command -v php || echo /usr/local/bin/php)"
fi

LOCK_FILE="${QUEUE_WORKER_LOCK:-$PROJECT_ROOT/storage/framework/queue-worker.lock}"
LOG_FILE="${QUEUE_WORKER_LOG:-$PROJECT_ROOT/storage/logs/queue-worker.log}"
# Test hook: stop after N worker runs (0 = forever).
MAX_RUNS="${QUEUE_WORKER_MAX_RUNS:-0}"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG_FILE"
}

# Single instance: fd 9 holds the lock for this loop's whole lifetime.
exec 9>"$LOCK_FILE" || exit 1
if ! flock -n 9; then
    exit 0
fi

log "queue-worker loop started (pid $$, php $PHP_BIN)"

runs=0
while true; do
    runs=$((runs + 1))
    log "starting queue:work (run $runs)"
    # 9>&- : the worker must not inherit the lock fd, or a worker that
    # outlives a killed loop would keep the next loop from starting.
    "$PHP_BIN" artisan queue:work database --sleep=1 --tries=3 --max-time=3540 --memory=256 9>&-
    code=$?
    log "queue:work exited (code $code); restarting in 1s"
    if [ "$MAX_RUNS" -gt 0 ] && [ "$runs" -ge "$MAX_RUNS" ]; then
        log "queue-worker loop stopping after $runs run(s) (QUEUE_WORKER_MAX_RUNS)"
        exit 0
    fi
    sleep 1
done
