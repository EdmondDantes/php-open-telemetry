<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry\Metrics\Prometheus;

use IfCastle\Core\Exceptions\ErrorException;
use IfCastle\OpenTelemetry\Metrics\MeterInterface;
use IfCastle\OpenTelemetry\Metrics\MeterStorageInterface;
use Prometheus\Collector;
use Prometheus\Counter;
use Prometheus\Gauge;
use Prometheus\Histogram;
use Prometheus\Summary;

final readonly class MeterAdapter implements MeterStorageInterface
{
    public function __construct(private Collector $collector) {}
    
    public function record(MeterInterface $meter, mixed $value, array $attributes = []): void
    {
        $attributes                 = $this->convertAttributes($attributes);
        
        // Normalize attributes from record and from meter
        $attributes                 = array_merge($this->convertAttributes($meter->getAttributes()), $attributes);
        
        if($this->collector instanceof Counter) {
            $this->collector->incBy($value, $attributes);
        } elseif($this->collector instanceof Histogram) {
            $this->collector->observe($value, $attributes);
        } elseif($this->collector instanceof Gauge) {
            $this->collector->set($value, $attributes);
        } elseif($this->collector instanceof Summary) {
            $this->collector->observe($value, $attributes);
        } else {
            throw new ErrorException([
                'template'          => 'Unknown collector type "{type}"',
                'type'              => get_debug_type($this->collector),
            ]);
        }
    }
    
    protected function convertAttributes(array $attributes): array
    {
        $result                     = [];
        
        foreach ($attributes as $key => $value) {
            $result[str_replace('.', '_', $key)] = $value;
        }
        
        return $result;
    }
}