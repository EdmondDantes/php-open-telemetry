<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

interface TelemetryContextResolverInterface
{
    public function newTelemetryContext(): TelemetryContextInterface;
    
    public function resolveTelemetryContext(): TelemetryContextInterface;
}