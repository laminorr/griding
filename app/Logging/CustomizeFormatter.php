<?php

namespace App\Logging;

use Monolog\Formatter\LineFormatter;

/**
 * سازگار با Laravel 10/11 و Monolog 3
 * - Tap روی Illuminate\Log\Logger فراخوانی می‌شود.
 * - از getLogger() (لاراول جدید) و getMonolog() (قدیمی‌تر) هر دو پشتیبانی می‌کند.
 */
class CustomizeFormatter
{
    public function __invoke($logger): void
    {
        // گرفتن شیء Monolog\Logger از رَپر Illuminate\Log\Logger
        $monolog = method_exists($logger, 'getLogger')
            ? $logger->getLogger()
            : (method_exists($logger, 'getMonolog') ? $logger->getMonolog() : null);

        if (!$monolog) {
            return; // سازگاری محافظه‌کارانه
        }

        // فرمت تک‌خطی تمیز
        $output = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
        $formatter = new LineFormatter($output, 'Y-m-d H:i:s', true, true);
        if (method_exists($formatter, 'ignoreEmptyContextAndExtra')) {
            $formatter->ignoreEmptyContextAndExtra(true);
        }

        foreach ($monolog->getHandlers() as $handler) {
            if (method_exists($handler, 'setFormatter')) {
                $handler->setFormatter($formatter);
            }
        }
    }
}
