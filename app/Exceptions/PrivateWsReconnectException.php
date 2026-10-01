<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown inside NobitexPrivateWsService's read loop when the current private
 * WebSocket connection must be dropped (token refresh failed, connection token
 * expired, server disconnect/close). The outer run() loop catches it and
 * reconnects with a fresh token after the usual backoff. The message never
 * contains the token or the websocketAuthParam.
 */
class PrivateWsReconnectException extends \RuntimeException
{
}
