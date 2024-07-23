<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

interface TraceExporterInterface
{
    public function exportTraces(ResourceInterface $resource, array $instrumentationScopes, array $spansByScope): void;
    
    public function exportLogs(ResourceInterface $resource, array $instrumentationScopes, array $logsByScope): void;
    
    public function deferredSendRawTelemetry(string $endpoint, string $payload): void;
}