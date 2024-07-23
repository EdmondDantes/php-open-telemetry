<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

use IfCastle\Core\Environment\SystemEnvironmentInterface;

final class TraceHelper
{
    public static function defineTraceId(SystemEnvironmentInterface $systemEnvironment): ?string
    {
        $traceId                    = $systemEnvironment->getRequestEnvironment()?->getRequestId();

        if($traceId !== null) {
            return $traceId;
        }
        
        return null;
    }
}