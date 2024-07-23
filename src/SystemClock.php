<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

final class SystemClock
{
    public const int NANOS_PER_SECOND   = 1_000_000_000;
    
    private static int $referenceTime   = 0;
    
    public static function now(): int
    {
        if(self::$referenceTime === 0) {
            self::init();
        }
        
        return self::$referenceTime + hrtime(true);
    }
    
    public static function toSeconds(int $nanos): float
    {
        return $nanos / self::NANOS_PER_SECOND;
    }
    
    private static function init(): void
    {
        if (self::$referenceTime > 0) {
            return;
        }
        
        self::$referenceTime = self::calculateReferenceTime(
            microtime(true),
            hrtime(true)
        );
    }
    
    /**
     * Calculates the reference time which is later used to calculate the current wall clock time in nanoseconds by
     * adding the current uptime.
     */
    private static function calculateReferenceTime(float $wallClockMicroTime, int $upTime): int
    {
        return ((int) ($wallClockMicroTime * self::NANOS_PER_SECOND)) - $upTime;
    }
}