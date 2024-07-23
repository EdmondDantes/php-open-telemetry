<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

use Psr\Log\LogLevel;

readonly class Log
{
    public function __construct(
        public int                  $timeUnixNano,
        public string               $level,
        public float|array|bool|int|string|null $body,
        public array                $attributes,
        public ?string              $traceId,
        public ?string              $spanId,
        public ?TraceFlagsEnum      $flags,
    ) {}
    
    public function getSeverityNumber(): int
    {
        return $this->errorLevelToSeverityNumber($this->level);
    }
    
    protected function errorLevelToSeverityNumber(string $level): int
    {
        // According to OpenTelemetry specification
        // @see https://opentelemetry.io/docs/specs/otel/logs/data-model/#field-severitynumber
        return match ($level) {
            LogLevel::EMERGENCY     => 23,
            LogLevel::ALERT         => 21,
            LogLevel::CRITICAL      => 20,
            LogLevel::ERROR         => 17,
            LogLevel::WARNING       => 14,
            LogLevel::NOTICE        => 13,
            LogLevel::INFO          => 9,
            LogLevel::DEBUG         => 1,
            default                 => 0
        };
    }
}