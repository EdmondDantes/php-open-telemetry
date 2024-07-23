<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

interface TimestampInterface
{
    public function getTimeUnixNano(): int;
}