<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry\Metrics\Nope;

use IfCastle\OpenTelemetry\InstrumentationScopeInterface;
use IfCastle\OpenTelemetry\Metrics\Counter;
use IfCastle\OpenTelemetry\Metrics\Histogram;
use IfCastle\OpenTelemetry\Metrics\MeterInterface;
use IfCastle\OpenTelemetry\Metrics\MeterProviderInterface;
use IfCastle\OpenTelemetry\Metrics\MeterStorageInterface;
use IfCastle\OpenTelemetry\Metrics\State;
use IfCastle\OpenTelemetry\Metrics\StateInterface;
use IfCastle\OpenTelemetry\Metrics\Summary;
use IfCastle\OpenTelemetry\Metrics\UpDownCounter;

class NopeProvider                   implements MeterProviderInterface
{
    private MeterStorageInterface $storage;
    
    public function __construct()
    {
        $this->storage              = new NopeStorage();
    }
    
    public function registerCounter(
        InstrumentationScopeInterface $instrumentationScope,
        string                        $name,
        ?string                       $unit = null,
        ?string                       $description = null,
        array                         $attributes = [],
        bool                          $isReset = false
    ): MeterInterface
    {
        return new Counter($this->storage, $instrumentationScope, $name, $unit, $description, $attributes);
    }
    
    public function registerUpDownCounter(
        InstrumentationScopeInterface $instrumentationScope,
        string                        $name,
        ?string                       $unit = null,
        ?string                       $description = null,
        array                         $attributes = [],
        bool                          $isReset = false
    ): MeterInterface
    {
        return new UpDownCounter($this->storage, $instrumentationScope, $name, $unit, $description, $attributes);
    }
    
    public function registerGauge(
        InstrumentationScopeInterface $instrumentationScope,
        string                        $name,
        ?string                       $unit = null,
        ?string                       $description = null,
        array                         $attributes = [],
        bool                          $isReset = false
    ): MeterInterface
    {
        return new Counter($this->storage, $instrumentationScope, $name, $unit, $description, $attributes);
    }
    
    public function registerHistogram(
        InstrumentationScopeInterface $instrumentationScope,
        string                        $name,
        ?string                       $unit = null,
        ?string                       $description = null,
        array                         $attributes = [],
        bool                          $isReset = false
    ): MeterInterface
    {
        return new Histogram($this->storage, $instrumentationScope, $name, $unit, $description, $attributes);
    }
    
    public function registerSummary(
        InstrumentationScopeInterface $instrumentationScope,
        string                        $name,
        ?string                       $unit = null,
        ?string                       $description = null,
        array                         $attributes = [],
        bool                          $isReset = false
    ): MeterInterface
    {
        return new Summary($this->storage, $instrumentationScope, $name, $unit, $description, $attributes);
    }
    
    public function registerState(
        InstrumentationScopeInterface $instrumentationScope,
        string                        $name,
        ?string                       $description = null,
        array                         $attributes = [],
        bool                          $isReset = false
    ): StateInterface
    {
        return new State($this->storage, $instrumentationScope, $name, 'count', $description, $attributes);
    }
}