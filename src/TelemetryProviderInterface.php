<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

use IfCastle\OpenTelemetry\Metrics\MeterProviderInterface;
use Psr\Log\LoggerInterface;

interface TelemetryProviderInterface extends MeterProviderInterface
{
    public function isMetricsEnabled(): bool;
    
    public function getMeterProvider(): MeterProviderInterface;
    
    public function provideLogger(InstrumentationScopeInterface $instrumentationScope): LoggerInterface;
}