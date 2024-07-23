<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

use IfCastle\OpenTelemetry\Protocol\FileTransport;
use IfCastle\OpenTelemetry\Protocol\TraceTemporaryStorageInterface;

final class TraceCriticalExporter   extends     TraceExporter
                                    implements  TraceTemporaryStorageInterface
{
    protected function defineTransport(): void
    {
        $this->transport            = new FileTransport();
    }
    
    public function deferredSendRawTelemetry(string $endpoint, string $payload): void
    {
        try {
            $this->send($endpoint, $payload);
        } catch(\Throwable) {
            // Ignore
        }
    }
    
    public function finalize(): bool
    {
        return $this->transport->finalize();
    }
    
    public function extractPackets(): iterable
    {
        yield from $this->transport->extractPackets();
    }
    
    public function clearOldPackets(): void
    {
        $this->transport->clearOldPackets();
    }
}