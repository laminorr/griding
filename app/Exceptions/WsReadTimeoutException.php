<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A WsFrameClient read waited its full socket timeout without a frame. Not a
 * drop by itself: NobitexPrivateWsService decides from the time since the last
 * frame (trading.websocket.private_max_silence_seconds) and from
 * isConnected() whether to keep reading or reconnect.
 */
class WsReadTimeoutException extends \RuntimeException
{
}
