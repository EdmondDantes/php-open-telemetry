<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

interface SpanElementInterface
{
    public function getTraceId(): ?string;
    public function getSpanId(): ?string;
}