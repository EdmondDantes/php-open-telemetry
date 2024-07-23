<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

use IfCastle\Core\FreeInterface;

interface TraceInterface            extends FreeInterface
{
    public function newSpanId(): string;
    public function getTraceId(): string;
    public function isExternal(): bool;
    public function getCurrentSpanId(): ?string;
    public function getCurrentSpan(): ?SpanInterface;
    public function getParentSpan(): ?SpanInterface;
    public function getResource(): ResourceInterface;
    public function setResource(ResourceInterface $resource): static;
    public function findInstrumentationScopeId(InstrumentationScopeInterface $instrumentationScope): string;
    public function createSpan(string $spanName, SpanKindEnum $spanKind, InstrumentationScopeInterface $instrumentationScope = null, array $attributes = []): SpanInterface;
    public function endSpan(SpanInterface $span = null): void;
    public function getInstrumentationScopes(): array;
    public function getSpansByInstrumentationScope(): array;
    public function end(): void;
    public function cleanSpans(): void;
}