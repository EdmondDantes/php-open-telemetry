<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

interface SpanLoggerInterface
{
    public function defineSpanKind(SpanKindEnum $spanKind): static;
    public function startSpan(string $spanName, array $attributes = []): SpanInterface;
    public function endSpan(SpanInterface $span = null): void;
}