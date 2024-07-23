<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry\Metrics\Nope;

use IfCastle\OpenTelemetry\Metrics\MeterInterface;
use IfCastle\OpenTelemetry\Metrics\MeterStorageInterface;

class NopeStorage           implements MeterStorageInterface
{
    public function record(MeterInterface $meter, mixed $value, array $attributes = []): void {}
    public function reset(MeterInterface $meter): void {}
}