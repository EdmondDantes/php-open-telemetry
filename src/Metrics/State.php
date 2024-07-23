<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry\Metrics;

use IfCastle\OpenTelemetry\InstrumentationScopeInterface;

class State                         extends MeterAbstract
                                    implements StateInterface
{
    public function __construct(
        MeterStorageInterface         $storage,
        InstrumentationScopeInterface $instrumentationScope,
        string                        $name,
        ?string                       $unit = null,
        ?string                       $description = null,
        array                         $attributes = []
    )
    {
        parent::__construct($storage, $instrumentationScope, $name, $unit, $description, $attributes);
    }
    
    public function setStateOk(string $message = ''): void
    {
        $this->add(0);
    }
    
    public function setStateError(string $message = ''): void
    {
        $this->add(1);
    }
}