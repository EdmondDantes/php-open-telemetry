<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

interface TelemetryAttributesAwareInterface
{
    public function getTelemetryAttributes(): AttributesInterface;
}