<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

use IfCastle\Core\Services\System\SystemClock;

class Event                         implements ElementInterface, AttributesInterface, TimestampInterface
{
    use ElementTrait;
    use AttributesTrait;
    use TimestampTrait;
    
    public function __construct(string $name, iterable $attributes = [], int $timestamp = null)
    {
        $this->name                 = $name;
        $this->attributes           = $attributes;
        $this->timestamp            = $timestamp ?? SystemClock::now();
    }
}