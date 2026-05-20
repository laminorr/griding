#!/bin/bash
# WebSocket consumer keep-alive wrapper
# Runs nobitex:ws-consumer if not already running

cd /home/savesir/grid-bot

# Check if WS consumer is already running
if pgrep -f "ws-consumer" > /dev/null; then
    # Already running, do nothing
    exit 0
fi

# Start it in the background, redirect output to log
nohup /usr/local/bin/php artisan nobitex:ws-consumer BTCIRT,ETHIRT,USDTIRT >> /home/savesir/grid-bot/storage/logs/ws-consumer.log 2>&1 &

# Log the start
echo "[$(date '+%Y-%m-%d %H:%M:%S')] WS consumer started (PID: $!)" >> /home/savesir/grid-bot/storage/logs/ws-keepalive.log
