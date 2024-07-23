<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry\Metrics;

use IfCastle\OpenTelemetry\InstrumentationScopeInterface;

interface MeterProviderInterface
{
    public function registerCounter(
        InstrumentationScopeInterface $instrumentationScope,
        string                        $name,
        ?string                       $unit = null,
        ?string                       $description = null,
        array                         $attributes = [],
        bool                          $isReset = false
    ): MeterInterface;
    
    public function registerUpDownCounter(
        InstrumentationScopeInterface $instrumentationScope,
        string                        $name,
        ?string                       $unit = null,
        ?string                       $description = null,
        array                         $attributes = [],
        bool                          $isReset = false
    ): MeterInterface;
    
    public function registerGauge(
        InstrumentationScopeInterface $instrumentationScope,
        string                        $name,
        ?string                       $unit = null,
        ?string                       $description = null,
        array                         $attributes = [],
        bool                          $isReset = false
    ): MeterInterface;
    
    public function registerHistogram(
        InstrumentationScopeInterface $instrumentationScope,
        string                        $name,
        ?string                       $unit = null,
        ?string                       $description = null,
        array                         $attributes = [],
        bool                          $isReset = false
    ): MeterInterface;
    
    public function registerSummary(
        InstrumentationScopeInterface $instrumentationScope,
        string                        $name,
        ?string                       $unit = null,
        ?string                       $description = null,
        array                         $attributes = [],
        bool                          $isReset = false
    ): MeterInterface;
    
    public function registerState(
        InstrumentationScopeInterface $instrumentationScope,
        string                        $name,
        ?string                       $description = null,
        array                         $attributes = [],
        bool                          $isReset = false
    ): StateInterface;
}