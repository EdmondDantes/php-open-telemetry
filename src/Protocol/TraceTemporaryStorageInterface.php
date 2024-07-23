<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry\Protocol;

interface TraceTemporaryStorageInterface
{
    public function finalize(): bool;
    public function extractPackets(): iterable;
    public function clearOldPackets(): void;
}