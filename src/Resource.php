<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

class Resource                  implements ResourceInterface
{
    use ElementTrait;
    use AttributesTrait;
    
    public function __construct(
        string $name,
        array $attributes = [],
    ) {
        $this->name             = $name;
        $this->attributes       = $attributes;
    }
}