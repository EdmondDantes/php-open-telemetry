<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

final class TelemetryAttributes implements AttributesInterface
{
    use AttributesTrait;
    
    public function __construct(array $attributes = [])
    {
        $this->setAttributes($attributes);
    }
}