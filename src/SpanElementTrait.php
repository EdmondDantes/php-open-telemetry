<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

trait SpanElementTrait
{
    protected string $traceId       = '';
    protected string $spanId        = '';
    
    public function getTraceId(): string
    {
        return $this->traceId;
    }
    
    public function setTraceId(string $traceId): static
    {
        $this->traceId              = $traceId;
        
        return $this;
    }
    
    public function getSpanId(): string
    {
        return $this->spanId;
    }
    
    public function setSpanId(string $spanId): static
    {
        $this->spanId               = $spanId;
        
        return $this;
    }
}