<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry\Metrics;

use IfCastle\OpenTelemetry\AttributesInterface;
use IfCastle\OpenTelemetry\ElementInterface;

interface MeterInterface            extends AttributesInterface, ElementInterface
{
    public function getMeterId(): string;
    public function add(mixed $value, array $attributes = []): void;
}