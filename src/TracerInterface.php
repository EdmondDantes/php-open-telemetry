<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

interface TracerInterface
{
    final public const TRACER       = 'tracer';
    
    public function getResource(): ResourceInterface;
    
    public function newTelemetryContext(): TelemetryContextInterface;
    
    public function createTrace(): TraceInterface;
    
    public function endTrace(TraceInterface $trace): void;
    
    public function createSpan(string $spanName, SpanKindEnum $spanKind, InstrumentationScopeInterface $instrumentationScope = null, array $attributes = []): SpanInterface;
    
    public function endSpan(SpanInterface $span = null): void;
    
    public function registerLog(
        InstrumentationScopeInterface $instrumentationScope,
        string $level,
        array|string|bool|int|float|null $body,
        array $attributes           = []
    ): void;
    
    /**
     * Add exception to the telemetry span context (if defined).
     *
     * @param \Throwable $throwable
     * @param array      $attributes
     */
    public function registerException(\Throwable $throwable, array $attributes = []): void;
    
    /**
     * Called every time when the trace (request) is finished.
     * @return void
     */
    public function flushTrace(): void;
    
    /**
     * Final flush of the telemetry.
     * @return void
     */
    public function flushTelemetry(bool $isCritical = false): void;
}