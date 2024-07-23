<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry\Metrics;

interface MeterStorageInterface
{
    public function record(MeterInterface $meter, mixed $value, array $attributes = []): void;
}