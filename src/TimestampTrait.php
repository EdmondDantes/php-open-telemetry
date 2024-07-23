<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

trait TimestampTrait
{
    protected int $timestamp;
    
    public function getTimeUnixNano(): int
    {
        return $this->timestamp;
    }
}