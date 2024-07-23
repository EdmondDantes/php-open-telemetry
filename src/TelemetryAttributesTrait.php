<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

trait TelemetryAttributesTrait
{
    protected AttributesInterface $telemetryAttributes;
    
    public function getTelemetryAttributes(): AttributesInterface
    {
        return $this->telemetryAttributes;
    }
}