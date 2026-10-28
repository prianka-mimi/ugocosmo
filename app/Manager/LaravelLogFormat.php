<?php

namespace App\Manager;

use Monolog\Formatter\LineFormatter;

class LaravelLogFormat
{
    public function __invoke($logger)
    {
        foreach ($logger->getHandlers() as $handler) {
            $format = "\n".str_repeat('=', 80)."\n".
                "📅 DATE   : [%datetime%]\n".
                "🚨 LEVEL  : %level_name%\n".
                "💬 MESSAGE: %message%\n".
                "🔍 CONTEXT: \n%context%\n".
                str_repeat('=', 80)."\n";

            $formatter = new LineFormatter($format, 'Y-m-d H:i:s', true, true);
            $formatter->setJsonPrettyPrint(true);
            $formatter->includeStacktraces(true);

            $handler->setFormatter($formatter);
        }
    }
}
