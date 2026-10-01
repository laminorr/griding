<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Minimal text-frame WebSocket client used by NobitexPrivateWsService, so the
 * read loop can be driven by a scripted fake in tests (no sockets).
 */
interface WsFrameClient
{
    public function send(string $payload): void;

    /** Next text frame; null/'' for an empty or close frame. */
    public function receive(): ?string;

    public function isConnected(): bool;

    public function close(): void;
}
