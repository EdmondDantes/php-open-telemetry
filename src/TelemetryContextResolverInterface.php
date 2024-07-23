<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

interface TelemetryContextResolverInterface
{
    public function resolveTelemetryContext(): TelemetryContextInterface;
}