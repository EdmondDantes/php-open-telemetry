<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

use IfCastle\Core\Environment\EnvironmentInterface;
use IfCastle\Core\FreeInterface;

interface TelemetryContextInterface      extends EnvironmentInterface, FreeInterface
{
    final public const TELEMETRY_CONTEXT = 'telemetryContext';
    
    public function getTraceId(): ?string;
    public function getSpanId(): ?string;
    public function getTraceFlags(): TraceFlagsEnum;
}