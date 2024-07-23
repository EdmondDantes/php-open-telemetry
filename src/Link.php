<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

class Link                 implements LinkInterface
{
    use ElementTrait;
    use AttributesTrait;
    use SpanElementTrait;
    
    public function __construct(
        string $traceId,
        string $spanId,
        array $attributes   = []
    )
    {
        $this->traceId      = $traceId;
        $this->spanId       = $spanId;
        $this->attributes   = $attributes;
    }
}