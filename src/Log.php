<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

use IfCastle\Logger\LoggerInterface;

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
            LoggerInterface::EMERGENCY => 23,
            LoggerInterface::ALERT     => 21,
            LoggerInterface::CRITICAL  => 20,
            LoggerInterface::ERROR     => 17,
            LoggerInterface::WARNING   => 14,
            LoggerInterface::NOTICE    => 13,
            LoggerInterface::INFO      => 9,
            LoggerInterface::DEBUG     => 1,
            default                    => 0
        };
    }
}