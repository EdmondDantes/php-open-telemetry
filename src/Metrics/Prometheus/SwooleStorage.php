<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry\Metrics\Prometheus;

use Prometheus\Collector;
use Prometheus\Math;
use Prometheus\MetricFamilySamples;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;
use Swoole\Atomic;
use Swoole\Table;

/**
 * ## Swoole Storage
 *
 * This storage is based on Swoole Table for Prometheus metrics.
 * This storage allows you to store metrics in memory across multiple processes.
 */
final class SwooleStorage           extends InMemory
{
    final const TYPE_COUNTER         = 1;
    final const TYPE_GAUGE           = 2;
    final const TYPE_HISTOGRAM       = 3;
    final const TYPE_SUMMARY         = 4;
    
    final const KEY_LENGTH           = 1024;
    final const META_LENGTH          = 1024 * 4;
    
    final const SEPARATOR            = "\x00";
    
    private int $metersMaxSize       = 1024 * 4;
    private int $timeSeriesMaxSize   = 1024 * 4;
    private Table  $meters;
    private Table  $samples;
    /**
     * @var Table
     */
    private Table  $meterTimeSeries;
    private Atomic $idGenerator;
    private Atomic $indexGenerator;
    
    public function __construct()
    {
        $this->wipeStorage();
    }
    
    public function collect(bool $sortMetrics = true): array
    {
        return array_merge($this->collectValues($sortMetrics), $this->collectTimeSeries());
    }
    
    private function collectValues(bool $sortMetrics = true): array
    {
        $meters                     = [];
        
        foreach ($this->meters as $meter) {
            
            if($meter['type'] !== self::TYPE_COUNTER && $meter['type'] !== self::TYPE_GAUGE) {
                continue;
            }
            
            $metaData               = json_decode($meter['meta'], true);
            
            if(!is_array($metaData)) {
                continue;
            }
            
            $meters[$meter['id']]   = [
                'name'              => $metaData['name'] ?? '',
                'help'              => $metaData['help'] ?? '',
                'type'              => $metaData['type'] ?? '',
                'labelNames'        => $metaData['labelNames'] ?? [],
                'samples'           => [],
            ];
        }
        
        foreach ($this->samples as $key => $sample) {
            
            $parts                  = explode(self::SEPARATOR, $key);
            
            if(count($parts) !== 2) {
                continue;
            }
            
            [$meterId, ]            = $parts;
            $valueKey               = $sample['key'];
            
            if(false === array_key_exists($meterId, $meters)) {
                $this->samples->del($key);
                continue;
            }

            $parts                  = explode(':', $valueKey);
            $labelValues            = $parts[2] ?? '';

            $meters[$meterId]['samples'][] = [
                'name'              => $meters[$meterId]['name'],
                'labelNames'        => [],
                'labelValues'       => $this->decodeLabelValues($labelValues),
                'value'             => $sample['value']
            ];
        }
        
        $result                     = [];
        
        foreach ($meters as $meter) {
            
            if ($sortMetrics) {
                $this->sortSamples($meter['samples']);
            }
            
            $result[]               = new MetricFamilySamples($meter);
        }
        
        return $result;
    }
    
    private function collectTimeSeries(): array
    {
        $meters                     = [];
        
        foreach ($this->meters as $key => $meter) {
            
            if($meter['type'] !== self::TYPE_SUMMARY && $meter['type'] !== self::TYPE_HISTOGRAM) {
                continue;
            }
            
            $meter['uniqueIndex']   = $key;
            $meterId                = $meter['id'];
            
            $metaData               = json_decode($meter['meta'], true);
            
            if(!is_array($metaData)) {
                continue;
            }
            
            $meters[$meterId]       = [
                'name'              => $metaData['name'] ?? '',
                'help'              => $metaData['help'] ?? '',
                'type'              => $metaData['type'] ?? '',
                'labelNames'        => $metaData['labelNames'] ?? [],
                'samples'           => [],
                'summary'           => [],
            ];
            
            if($meter['type'] === self::TYPE_SUMMARY) {
                $meters[$meterId]['maxAgeSeconds']  = $metaData['maxAgeSeconds'] ?? 0;
                $meters[$meterId]['quantiles']      = $metaData['quantiles'] ?? [];
            } elseif ($meter['type'] === self::TYPE_HISTOGRAM) {
                $meters[$meterId]['buckets']        = $metaData['buckets'] ?? [];
                $meters[$meterId]['buckets'][]      = '+Inf';
            }
        }
        
        $now                        = time();
        
        foreach ($this->meterTimeSeries as $key => $sample) {

            $parts                  = explode(self::SEPARATOR, $key);
            
            if(count($parts) === 3) {
                [$meterId, , ]      = $parts;
                $valueKey           = $sample['key'];
            } else {
                [$meterId, $valueKey] = $parts;
            }
            
            if(false === array_key_exists($meterId, $meters)) {
                $this->meterTimeSeries->del($key);
                continue;
            }

            // Is it old data?
            if(!empty($meters[$meterId]['maxAgeSeconds']) && ($now - $sample['time']) > $meters[$meterId]['maxAgeSeconds']) {
                $this->meterTimeSeries->del($key);
                continue;
            }
            
            if($meters[$meterId]['type'] === 'summary') {
                $meters[$meterId]['summary'][$valueKey][] = $sample['value'];
            } else {
                $meters[$meterId]['summary'][$valueKey] = $sample['value'];
            }
        }
        
        $math                       = new Math();
        $metrics                    = [];
    
        foreach ($meters as $meter) {
            
            if ($meter['type'] === 'summary') {
                
                // Convert summary to samples
                $summary                = $meter['summary'];

                foreach ($summary as $key => $values) {
                    
                    $parts              = explode(':', $key);
                    $labelValues        = $parts[2] ?? [];
                    $decodedLabelValues = $this->decodeLabelValues($labelValues);

                    // Compute quantiles
                    sort($values);

                    foreach ($meter['quantiles'] as $quantile) {
                        $meter['samples'][] = [
                            'name'          => $meter['name'],
                            'labelNames'    => ['quantile'],
                            'labelValues'   => array_merge($decodedLabelValues, [$quantile]),
                            'value'         => $math->quantile($values, $quantile),
                        ];
                    }
                    
                    // Add the count
                    $meter['samples'][]     = [
                        'name'              => $meter['name'] . '_count',
                        'labelNames'        => [],
                        'labelValues'       => $decodedLabelValues,
                        'value'             => count($values),
                    ];
                    
                    // Add the sum
                    $meter['samples'][]     = [
                        'name'              => $meter['name'] . '_sum',
                        'labelNames'        => [],
                        'labelValues'       => $decodedLabelValues,
                        'value'             => array_sum($values),
                    ];
                }
                
                if(count($meter['samples']) === 0) {
                    $this->meters->del($meter['uniqueIndex']);
                    continue;
                }
                
            } else {
                
                // Histogram
                $histogramBuckets       = [];
                
                foreach ($meter['summary'] as $key => $value) {
                    $parts              = explode(':', $key);
                    $labelValues        = $parts[2];
                    $bucket             = $parts[3];
                    
                    // Key by labelValues
                    $histogramBuckets[$labelValues][$bucket] = $value;
                }

                unset($meter['summary']);

                // Compute all buckets
                $labels                 = array_keys($histogramBuckets);
                
                sort($labels);
                
                foreach ($labels as $labelValues) {
                    
                    $acc                = 0;
                    
                    $decodedLabelValues = $this->decodeLabelValues($labelValues);
                    
                    foreach ($meter['buckets'] as $bucket) {
                        $bucket         = (string)$bucket;
                        
                        if (!isset($histogramBuckets[$labelValues][$bucket])) {
                            $meter['samples'][]     = [
                                'name'              => $meter['name'] . '_bucket',
                                'labelNames'        => ['le'],
                                'labelValues'       => array_merge($decodedLabelValues, [$bucket]),
                                'value'             => $acc,
                            ];
                        } else {
                            $acc                    += $histogramBuckets[$labelValues][$bucket];
                            $meter['samples'][]      = [
                                'name'              => $meter['name'] . '_' . 'bucket',
                                'labelNames'        => ['le'],
                                'labelValues'       => array_merge($decodedLabelValues, [$bucket]),
                                'value'             => $acc,
                            ];
                        }
                    }
                    
                    // Add the count
                    $meter['samples'][]             = [
                        'name'                      => $meter['name'] . '_count',
                        'labelNames'                => [],
                        'labelValues'               => $decodedLabelValues,
                        'value'                     => $acc,
                    ];
                    
                    // Add the sum
                    $meter['samples'][]             = [
                        'name'                      => $meter['name'] . '_sum',
                        'labelNames'                => [],
                        'labelValues'               => $decodedLabelValues,
                        'value'                     => $histogramBuckets[$labelValues]['sum'],
                    ];
                }
            }

            $metrics[]              = new MetricFamilySamples($meter);
        }
        
        return $metrics;
    }
    
    public function updateSummary(array $data): void
    {
        $metaKey                    = $this->metaKey($data);
        $valueKey                   = $this->valueKey($data);
        
        $meterId                    = $this->getOrCreateMeterId($metaKey, self::TYPE_SUMMARY, $data);
        
        if($meterId === 0) {
            return;
        }
        
        $time                       = time();
        $this->cleanTimeSeries($time);

        $index                      = $this->indexGenerator->add();
        $crc32                      = crc32($valueKey);
        
        $this->addToTimeSeries($meterId.self::SEPARATOR.$index.self::SEPARATOR.$crc32, ['value' => (float)$data['value'], 'key' => $valueKey, 'time' => $time]);
    }
    
    public function updateHistogram(array $data): void
    {
        $metaKey                    = $this->metaKey($data);
        $sumKey                     = $this->histogramBucketValueKey($data, 'sum');
        
        $meterId                    = $this->getOrCreateMeterId($metaKey, self::TYPE_HISTOGRAM, $data);
        
        if($meterId === 0) {
            return;
        }
        
        $time                       = time();
        $this->cleanTimeSeries($time);
        
        $row                        = $this->meterTimeSeries->get($meterId.self::SEPARATOR.$sumKey);

        if($row === false) {
            $this->addToTimeSeries($meterId.self::SEPARATOR.$sumKey, ['value' => (float)$data['value'], 'time' => $time, 'key' => '']);
        } else {
            $this->addToTimeSeries($meterId.self::SEPARATOR.$sumKey, ['value' => $row['value'] + (float)$data['value'], 'time' => $time, 'key' => '']);
        }
        
        $bucketToIncrease           = '+Inf';
        
        foreach ($data['buckets'] as $bucket) {
            if ($data['value'] <= $bucket) {
                $bucketToIncrease = $bucket;
                break;
            }
        }
        
        $bucketKey                  = $this->histogramBucketValueKey($data, $bucketToIncrease);
        
        $row                        = $this->meterTimeSeries->get($meterId.self::SEPARATOR.$bucketKey);

        if($row === false) {
            $this->addToTimeSeries($meterId.self::SEPARATOR.$bucketKey, ['value' => 1.0, 'time' => $time, 'key' => '']);
            return;
        }
        
        $this->addToTimeSeries($meterId.self::SEPARATOR.$bucketKey, ['value' => $row['value'] + 1.0, 'time' => $time, 'key' => '']);
    }
    
    private function addToTimeSeries(string $key, array $data): void
    {
        try {
            $this->meterTimeSeries->set($key, $data);
            return;
        } catch (\Throwable) {
        }
        
        if($this->meterTimeSeries->count() >= $this->timeSeriesMaxSize) {
            // remove old data
            foreach ($this->meterTimeSeries as $key => $row) {
                $this->meterTimeSeries->del($key);
                break;
            }
        }
        
        try {
            $this->meterTimeSeries->set($key, $data);
        } catch (\Throwable) {
        }
    }
    
    public function updateGauge(array $data): void
    {
        $this->updateValue($data, self::TYPE_GAUGE);
    }
    
    public function resetGauge(Collector $collector, array $convertedAttributes): void
    {
        $data                       = [
            'name'                  => $collector->getName(),
            'type'                  => $collector->getType(),
            'labelNames'            => $collector->getLabelNames(),
            'labelValues'           => $convertedAttributes,
            'value'                 => 0,
            'command'               => Adapter::COMMAND_SET
        ];
        
        $this->updateValue($data, self::TYPE_GAUGE);
    }
    
    public function updateCounter(array $data): void
    {
        $this->updateValue($data, self::TYPE_COUNTER);
    }
    
    public function resetCounter(Collector $collector, array $convertedAttributes): void
    {
        $data                       = [
            'name'                  => $collector->getName(),
            'type'                  => $collector->getType(),
            'labelNames'            => $collector->getLabelNames(),
            'labelValues'           => $convertedAttributes,
            'value'                 => 0,
            'command'               => Adapter::COMMAND_SET
        ];
        
        $this->updateValue($data, self::TYPE_COUNTER);
    }

    private function updateValue(array $data, int $type): void
    {
        $metaKey                    = $this->metaKey($data);
        $valueKey                   = $this->valueKey($data);
        
        $meterId                    = $this->getOrCreateMeterId($metaKey, $type, $data);
        
        if($meterId === 0) {
            return;
        }

        $hash                       = $meterId.self::SEPARATOR.crc32($valueKey);
        
        if($this->samples->count() >= $this->metersMaxSize) {
            // Remove old data
            foreach ($this->samples as $key => $row) {
                $this->samples->del($key);
                break;
            }
        }
        
        if ($data['command'] === Adapter::COMMAND_SET) {
            $this->samples->set($hash, ['value' => (float)$data['value'], 'key' => $valueKey]);
        } else {
            if (false === $this->samples->exists($hash)) {
                $this->samples->set($hash, ['value' => (float)$data['value'], 'key' => $valueKey]);
            } else {
                $this->samples->incr($hash, 'value', (float)$data['value']);
            }
        }
    }
    
    protected function valueKey(array $data): string
    {
        $valueKey                   = parent::valueKey($data);
        
        if(strlen($valueKey) > self::KEY_LENGTH) {
            $data['labelValues']    = [];
            return parent::valueKey($data);
        }

        return $valueKey;
    }
    
    protected function getOrCreateMeterId(string $metaKey, int $type, array $data): int
    {
        $meterId                    = $this->meters->get($metaKey, 'id');
        
        if ($meterId === false) {
            
            // We can't add more meters
            if($this->meters->count() >= $this->metersMaxSize) {
                return 0;
            }
            
            $meterId                = $this->idGenerator->add();
            
            $this->meters->set($metaKey, [
                'id'                => $meterId,
                'type'              => $type,
                'meta'              => $this->metaDataToString($this->metaData($data))
            ]);
        }
        
        return $meterId;
    }
    
    private function cleanTimeSeries(int $time): void
    {
        // Try to remove old data
        if($this->meterTimeSeries->count() > 1024 * 1000) {
            foreach ($this->meterTimeSeries as $key => $row) {
                // 24 hours
                if($row['time'] < $time - 86400) {
                    $this->meterTimeSeries->del($key);
                }
            }
        } else {
            return;
        }
        
        // Try to remove old data (1 hour)
        if($this->meterTimeSeries->count() > 1024 * 1000) {
            foreach ($this->meterTimeSeries as $key => $row) {
                // 1 hour
                if($row['time'] < $time - 3600) {
                    $this->meterTimeSeries->del($key);
                }
            }
        } else {
            return;
        }
        
        if($this->meterTimeSeries->count() > 1024 * 1000) {
            // Remove 10 items
            $count                  = 10;
            
            foreach ($this->meterTimeSeries as $key => $row) {
                $this->meterTimeSeries->del($key);
                $count--;
                
                if($count <= 0) {
                    break;
                }
            }
        }
    }
    
    private function metaDataToString(array $metaData): string
    {
        $string                     = json_encode($metaData);
        
        if(strlen($string) < self::META_LENGTH) {
            return $string;
        }
        
        $total                      = 2;
        $newMetaData                = [];
        
        foreach ($metaData as $key => $value) {
            
            if (is_string($key) === false) {
                continue;
            }
            
            if(is_scalar($value) === false) {
                continue;
            }
            
            $value                  = (string) $value;
            
            if(strlen($key) > 64) {
                $key                = substr($key, 0, 64 - 3) . '...';
            }
            
            if(strlen($value) > 512) {
                $value              = substr($value, 0, 512 - 3) . '...';
            }
            
            $total                  += strlen($key) + strlen($value) + 8;
            
            if($total > self::META_LENGTH - 32) {
                return json_encode($newMetaData);
            }
            
            $newMetaData[$key]      = $value;
        }
        
        return json_encode($newMetaData);
    }
    
    public function wipeStorage(): void
    {
        $this->meters               = new Table($this->metersMaxSize);
        $this->samples              = new Table($this->metersMaxSize);
        
        $this->meters->column('id', Table::TYPE_INT);
        $this->meters->column('type', Table::TYPE_INT);
        $this->meters->column('meta', Table::TYPE_STRING, self::META_LENGTH);
        $this->meters->create();
        
        $this->samples->column('key', Table::TYPE_STRING, self::KEY_LENGTH);
        $this->samples->column('value', Table::TYPE_FLOAT);
        $this->samples->create();
        
        $this->meterTimeSeries      = new Table($this->timeSeriesMaxSize);
        $this->meterTimeSeries->column('key', Table::TYPE_STRING, self::KEY_LENGTH);
        $this->meterTimeSeries->column('time', Table::TYPE_INT);
        $this->meterTimeSeries->column('value', Table::TYPE_FLOAT);
        $this->meterTimeSeries->create();
        
        $this->idGenerator          = new Atomic(0);
        $this->indexGenerator       = new Atomic(0);
    }
}