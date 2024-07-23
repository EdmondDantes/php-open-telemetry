<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry\Metrics;

use IfCastle\OpenTelemetry\AttributesTrait;
use IfCastle\OpenTelemetry\ElementTrait;
use IfCastle\OpenTelemetry\InstrumentationScopeInterface;

abstract class MeterAbstract        implements MeterInterface
{
    use ElementTrait;
    use AttributesTrait;
    
    public function __construct(
        protected MeterStorageInterface $storage,
        protected InstrumentationScopeInterface $instrumentationScope,
        string $name,
        protected ?string $unit                 = null,
        protected ?string $description          = null,
        array $attributes                       = []
    )
    {
        $this->name                 = $name;
        $this->attributes           = $attributes;
    }
    
    public function getMeterId(): string
    {
        return 'm'.spl_object_id($this);
    }
    
    public function add(mixed $value, array $attributes = []): void
    {
        $this->storage->record($this, $value, $attributes);
    }
}