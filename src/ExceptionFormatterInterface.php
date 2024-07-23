<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

interface ExceptionFormatterInterface
{
    public function buildExceptionAttributes(\Throwable $throwable): array;
}