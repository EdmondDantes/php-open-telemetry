<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry\Protocol;

interface TransportInterface
{
    final public const PROTOBUF     = 'application/x-protobuf';
    final public const JSON         = 'application/json';
    final public const NDJSON       = 'application/x-ndjson';
    
    final public const ENDPOINT_LOGS = 'logs';
    final public const ENDPOINT_TRACES = 'traces';
    final public const ENDPOINT_METRICS = 'metrics';
    
    public function setConfig(array $config): static;
    public function contentType(): string;
    public function send(string $endpoint, string $body): ?string;
}