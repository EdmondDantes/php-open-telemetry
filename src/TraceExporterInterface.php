<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

interface TraceExporterInterface
{
    final public const TRACE_EXPORTER = 'traceExporter';
    final public const TRACE_CRITICAL_EXPORTER = 'traceCriticalExporter';
    
    public function exportTraces(ResourceInterface $resource, array $instrumentationScopes, array $spansByScope): void;
    public function exportLogs(ResourceInterface $resource, array $instrumentationScopes, array $logsByScope): void;
    public function deferredSendRawTelemetry(string $endpoint, string $payload): void;
}