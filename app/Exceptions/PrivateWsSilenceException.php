<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The private WebSocket received no frame at all (not even a Centrifugo {}
 * ping) for trading.websocket.private_max_silence_seconds. The connection is
 * presumed dead and is dropped; unlike a planned reconnect (token expiry,
 * server disconnect) this is a genuine drop for log-level purposes.
 */
class PrivateWsSilenceException extends PrivateWsReconnectException
{
}
