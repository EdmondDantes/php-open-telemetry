<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry\Metrics;

interface StateInterface            extends MeterInterface
{
    public function setStateOk(string $message = ''): void;
    public function setStateError(string $message = ''): void;
}