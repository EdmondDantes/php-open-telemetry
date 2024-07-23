<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

use IfCastle\Logger\LoggerInterface;
use IfCastle\OpenTelemetry\Metrics\MeterProviderInterface;

interface TelemetryProviderInterface extends MeterProviderInterface
{
    final public const TYPE_LOGGER  = 'type.logger';
    final public const DEFAULT      = '';
    final public const DATABASE     = 'db';
    final public const RPC          = 'rpc';
    
    final public const TELEMETRY_PROVIDER   = 'telemetryProvider';
    final public const TELEMETRY_LOGGERS    = 'telemetryLoggers';
    
    public function isMetricsEnabled(): bool;
    
    public function getMeterProvider(): MeterProviderInterface;
    
    public function provideLogger(InstrumentationScopeInterface $instrumentationScope): LoggerInterface;
}