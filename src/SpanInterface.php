<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

/**
 * @see https://github.com/open-telemetry/opentelemetry-specification/blob/v1.6.1/specification/trace/api.md#span-operations
 */
interface SpanInterface             extends SpanElementInterface, AttributesInterface, ElementInterface, TimestampInterface
{
    public function getParentSpanId(): ?string;
    public function getTraceFlags(): TraceFlagsEnum;
    public function getSpanName(): string;
    public function getSpanKind(): SpanKindEnum;
    public function getStartTime(): int;
    public function getEndTime(): int;
    public function getDuration(): int;
    public function getDurationNanos(): int;
    public function getTraceState(): TraceState;
    public function getEvents(): array;
    public function addEvent(string $name, iterable $attributes = [], int $timestamp = null): static;
    public function recordException(\Throwable $exception, array $attributes = []): static;
    public function getStatus(): StatusCodeEnum;
    public function getStatusDescription(): string;
    public function setStatus(StatusCodeEnum $status, string $description = ''): static;
    public function isRecording(): bool;
    public function hasEnded(): bool;
    public function end(int $endEpochNanos = null): void;
    public function getLinks(): array;
    public function addLink(LinkInterface $link): static;
    public function getInstrumentationScope(): ?InstrumentationScopeInterface;
}