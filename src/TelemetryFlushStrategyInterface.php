<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

interface TelemetryFlushStrategyInterface
{
    public function flushTrace(TraceInterface $trace): void;
}