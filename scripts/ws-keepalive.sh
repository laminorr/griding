#!/bin/bash
# WebSocket consumer keep-alive wrapper
# Runs nobitex:ws-consumer if not already running

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

# Prefer ea-php83 (cPanel guarantees this stays at PHP 8.3).
# Fall back to ea-php84, then generic php in PATH.
if [ -x "/usr/local/bin/ea-php83" ]; then
    PHP_BIN="/usr/local/bin/ea-php83"
elif [ -x "/usr/local/bin/ea-php84" ]; then
    PHP_BIN="/usr/local/bin/ea-php84"
else
    PHP_BIN="$(command -v php || echo /usr/local/bin/php)"
fi

# Check if WS consumer is already running
if pgrep -f "ws-consumer" > /dev/null; then
    exit 0
fi

# Start it in the background, redirect output to log
nohup "$PHP_BIN" artisan nobitex:ws-consumer BTCIRT,ETHIRT,USDTIRT >> storage/logs/ws-consumer.log 2>&1 &

# Log the start
echo "[$(date '+%Y-%m-%d %H:%M:%S')] WS consumer started (PID: $!)" >> storage/logs/ws-keepalive.log
