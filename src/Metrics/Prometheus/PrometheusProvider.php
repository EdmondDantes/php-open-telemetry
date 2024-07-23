<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry\Metrics\Prometheus;

use IfCastle\Core\Config\FromConfig;
use IfCastle\Core\DI\AnnotationsTrait;
use IfCastle\Core\DI\Dependency;
use IfCastle\Core\DI\InjectInterface;
use IfCastle\Core\DI\InjectorTrait;
use IfCastle\Core\StatefulServers\StatefulServerInterface;
use IfCastle\Core\StatefulServers\Swoole\HttpServer\HttpServer;
use IfCastle\Core\StatefulServers\Swoole\Swoole;
use IfCastle\OpenTelemetry\InstrumentationScopeInterface;
use IfCastle\OpenTelemetry\Metrics\Counter;
use IfCastle\OpenTelemetry\Metrics\Histogram;
use IfCastle\OpenTelemetry\Metrics\MeterInterface;
use IfCastle\OpenTelemetry\Metrics\MeterProviderInterface;
use IfCastle\OpenTelemetry\Metrics\State;
use IfCastle\OpenTelemetry\Metrics\StateInterface;
use IfCastle\OpenTelemetry\Metrics\Summary;
use IfCastle\OpenTelemetry\Metrics\UpDownCounter;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\APC;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Redis;

/**
 * Adapter for Prometheus metrics collector provider.
 */
final class PrometheusProvider      implements MeterProviderInterface, InjectInterface
{
    use InjectorTrait;
    use AnnotationsTrait;
    
    #[FromConfig('metrics-collector')]
    protected array $config;
    
    protected array $metricsMap     = [];
    
    #[Dependency]
    protected ?StatefulServerInterface $statefulServer;
    
    private CollectorRegistry       $collectorRegistry;
    private ?SwooleStorage          $swooleStorage;
    
    public function initializeAfterInject(): void
    {
        // If Swoole is supported, use Swoole storage adapter
        if(Swoole::isSupported()) {
            $this->config['storage'] = 'swoole';
        }
        
        $storageAdapter             = match ($this->config['storage'] ?? '') {
            'redis'                 => new Redis($this->config['redis'] ?? []),
            'apcu'                  => new APC(),
            'swoole'                => new SwooleStorage(),
            default                 => new InMemory()
        };
        
        if($storageAdapter instanceof SwooleStorage) {
            $this->swooleStorage    = $storageAdapter;
        }
        
        $this->collectorRegistry    = new CollectorRegistry($storageAdapter);
    }
    
    public function render(): string
    {
        $renderer                   = new RenderTextFormat();
        $text                       = $renderer->render($this->collectorRegistry->getMetricFamilySamples());

        // Add swoole metrics to general metrics
        if($this->statefulServer instanceof HttpServer) {
            $text                   .= $this->swooleStatsToMetrics($this->statefulServer->getServer()->stats());
        }
        
        return $text;
    }
    
    public function getCollectorRegistry(): CollectorRegistry
    {
        return $this->collectorRegistry;
    }
    
    public function registerCounter(
        InstrumentationScopeInterface $instrumentationScope,
        string                        $name,
        ?string                       $unit = null,
        ?string                       $description = null,
        array                         $attributes = [],
        bool                          $isReset = false
    ): MeterInterface
    {
        $convertedAttributes        = $this->convertAttributes($attributes);
        
        $collector                  = $this->collectorRegistry->getOrRegisterCounter(
            $this->escapeName($instrumentationScope->getName()),
            $this->escapeName($name),
            $description ?? '',
            array_keys($convertedAttributes)
        );
        
        $id                         = spl_object_id($collector);
        
        if(isset($this->metricsMap[$id])) {
            return $this->metricsMap[$id];
        }
        
        $object                     = new Counter(
            new MeterAdapter($collector),
            $instrumentationScope,
            $name,
            $unit,
            $description,
            $attributes
        );
        
        $this->metricsMap[$id]      = $object;
        
        if($isReset) {
            $this->swooleStorage?->resetCounter($collector, $convertedAttributes);
        }
        
        return $object;
    }
    
    public function registerUpDownCounter(
        InstrumentationScopeInterface $instrumentationScope,
        string                        $name,
        ?string                       $unit = null,
        ?string                       $description = null,
        array                         $attributes = [],
        bool                          $isReset = false
    ): MeterInterface
    {
        $convertedAttributes        = $this->convertAttributes($attributes);
        
        $collector                  = $this->collectorRegistry->getOrRegisterCounter(
            $this->escapeName($instrumentationScope->getName()),
            $this->escapeName($name),
            $description ?? '',
            array_keys($convertedAttributes)
        );
        
        $id                         = spl_object_id($collector);
        
        if(isset($this->metricsMap[$id])) {
            return $this->metricsMap[$id];
        }
        
        $object                     = new UpDownCounter(
            new MeterAdapter($collector),
            $instrumentationScope,
            $name,
            $unit,
            $description,
            $attributes
        );
        
        $this->metricsMap[$id]      = $object;
        
        if($isReset) {
            $this->swooleStorage?->resetCounter($collector, $convertedAttributes);
        }
        
        return $object;
    }
    
    public function registerGauge(
        InstrumentationScopeInterface $instrumentationScope,
        string                        $name,
        ?string                       $unit = null,
        ?string                       $description = null,
        array                         $attributes = [],
        bool                          $isReset = false
    ): MeterInterface
    {
        $convertedAttributes        = $this->convertAttributes($attributes);
        
        $collector                  = $this->collectorRegistry->getOrRegisterGauge(
            $this->escapeName($instrumentationScope->getName()),
            $this->escapeName($name),
            $description ?? '',
            array_keys($convertedAttributes)
        );
        
        $id                         = spl_object_id($collector);
        
        if(isset($this->metricsMap[$id])) {
            return $this->metricsMap[$id];
        }
        
        $object                     = new Counter(
            new MeterAdapter($collector),
            $instrumentationScope,
            $name,
            $unit,
            $description,
            $attributes
        );
        
        $this->metricsMap[$id]      = $object;
        
        if($isReset) {
            $this->swooleStorage?->resetGauge($collector, $convertedAttributes);
        }
        
        return $object;
    }
    
    public function registerHistogram(
        InstrumentationScopeInterface $instrumentationScope,
        string                        $name,
        ?string                       $unit = null,
        ?string                       $description = null,
        array                         $attributes = [],
        bool                          $isReset = false
    ): MeterInterface
    {
        $collector                  = $this->collectorRegistry->getOrRegisterHistogram(
            $this->escapeName($instrumentationScope->getName()),
            $this->escapeName($name),
            $description ?? '',
            array_keys($this->convertAttributes($attributes))
        );
        
        $id                         = spl_object_id($collector);
        
        if(isset($this->metricsMap[$id])) {
            return $this->metricsMap[$id];
        }
        
        $object                     = new Histogram(
            new MeterAdapter($collector),
            $instrumentationScope,
            $name,
            $unit,
            $description,
            array_keys($this->convertAttributes($attributes))
        );
        
        $this->metricsMap[$id]      = $object;
        
        return $object;
    }
    
    public function registerSummary(
        InstrumentationScopeInterface $instrumentationScope,
        string                        $name,
        ?string                       $unit = null,
        ?string                       $description = null,
        array                         $attributes = [],
        bool                          $isReset = false
    ): MeterInterface
    {
        $collector                  = $this->collectorRegistry->getOrRegisterSummary(
            $this->escapeName($instrumentationScope->getName()),
            $this->escapeName($name),
            $description ?? '',
            array_keys($this->convertAttributes($attributes))
        );
        
        $id                         = spl_object_id($collector);
        
        if(isset($this->metricsMap[$id])) {
            return $this->metricsMap[$id];
        }
        
        $object                     = new Summary(
            new MeterAdapter($collector),
            $instrumentationScope,
            $name,
            $unit,
            $description,
            $attributes
        );
        
        $this->metricsMap[$id]      = $object;
        
        return $object;
    }
    
    public function registerState(
        InstrumentationScopeInterface $instrumentationScope,
        string                        $name,
        ?string                       $description = null,
        array                         $attributes = [],
        bool                          $isReset = false
    ): StateInterface
    {
        $convertedAttributes        = $this->convertAttributes($attributes);
        
        $collector                  = $this->collectorRegistry->getOrRegisterGauge(
            $this->escapeName($instrumentationScope->getName()),
            $this->escapeName($name),
            $description ?? '',
            array_keys($convertedAttributes)
        );
        
        $id                         = spl_object_id($collector);
        
        if(isset($this->metricsMap[$id])) {
            return $this->metricsMap[$id];
        }
        
        $object                     = new State(
            new MeterAdapter($collector),
            $instrumentationScope,
            $name,
            'count',
            $description,
            $attributes
        );
        
        $this->metricsMap[$id]      = $object;
        
        if($isReset) {
            $this->swooleStorage?->resetGauge($collector, $convertedAttributes);
        }
        
        return $object;
    }
    
    protected function escapeName(string $name): string
    {
        return str_replace(['.', ':', '=', ','], '_', $name);
    }
    
    protected function convertAttributes(array $attributes): array
    {
        $result                     = [];
        
        foreach ($attributes as $key => $value) {
            $result[str_replace('.', '_', $key)] = $value;
        }
        
        return $result;
    }
    
    protected function swooleStatsToMetrics(array $stats): string
    {
        $event_workers              = '';
        $event_workers              .= "# TYPE event_workers_start_time gauge\n";
        
        foreach ($stats['event_workers'] as $stat) {
            $event_workers          .= "event_workers_start_time{worker_id=\"{$stat['worker_id']}\"} {$stat['start_time']}\n";
        }
        
        $event_workers              .= "# TYPE event_workers_start_seconds gauge\n";
        
        foreach ($stats['event_workers'] as $stat) {
            $event_workers          .= "event_workers_start_seconds{worker_id=\"{$stat['worker_id']}\"} {$stat['start_seconds']}\n";
        }
        
        $event_workers              .= "# TYPE event_workers_dispatch_count gauge\n";
        
        foreach ($stats['event_workers'] as $stat) {
            $event_workers          .= "event_workers_dispatch_count{worker_id=\"{$stat['worker_id']}\"} {$stat['dispatch_count']}\n";
        }
        
        $event_workers              .= "# TYPE event_workers_request_count gauge\n";
        
        foreach ($stats['event_workers'] as $stat) {
            $event_workers          .= "event_workers_request_count{worker_id=\"{$stat['worker_id']}\"} {$stat['request_count']}\n";
        }
        
        $task_workers               = '';
        
        $task_workers               .= "# TYPE task_workers_start_time gauge\n";
        
        foreach ($stats['task_workers'] as $stat) {
            $task_workers           .= "task_workers_start_time{worker_id=\"{$stat['worker_id']}\"} {$stat['start_time']}\n";
        }
        
        $task_workers               .= "# TYPE task_workers_start_seconds gauge\n";
        
        foreach ($stats['task_workers'] as $stat) {
            $task_workers           .= "task_workers_start_seconds{worker_id=\"{$stat['worker_id']}\"} {$stat['start_seconds']}\n";
        }
        
        $user_workers = '';
        $user_workers               .= "# TYPE user_workers_start_time gauge\n";
        
        foreach ($stats['user_workers'] as $stat) {
            $user_workers           .= "user_workers_start_time{worker_id=\"{$stat['worker_id']}\"} {$stat['start_time']}\n";
        }
        
        $user_workers               .= "# TYPE user_workers_start_seconds gauge\n";
        
        foreach ($stats['user_workers'] as $stat) {
            $user_workers           .= "user_workers_start_seconds{worker_id=\"{$stat['worker_id']}\"} {$stat['start_seconds']}\n";
        }
        
        return "# TYPE info gauge\n"
               . "info{version=\"{$stats['version']}\"} 1\n"
               . "# TYPE up gauge\n"
               . "up {$stats['up']}\n"
               . "# TYPE reactor_num gauge\n"
               . "reactor_threads_num {$stats['reactor_threads_num']}\n"
               . "# TYPE requests counter\n"
               . "requests_total {$stats['requests_total']}\n"
               . "# TYPE start_time gauge\n"
               . "start_time {$stats['start_time']}\n"
               . "# TYPE max_conn gauge\n"
               . "max_conn {$stats['max_conn']}\n"
               . "# TYPE coroutine_num gauge\n"
               . "coroutine_num {$stats['coroutine_num']}\n"
               . "# TYPE start_seconds gauge\n"
               . "start_seconds {$stats['start_seconds']}\n"
               . "# TYPE workers_total gauge\n"
               . "workers_total {$stats['workers_total']}\n"
               . "# TYPE workers_idle gauge\n"
               . "workers_idle {$stats['workers_idle']}\n"
               . "# TYPE task_workers_total gauge\n"
               . "task_workers_total {$stats['task_workers_total']}\n"
               . "# TYPE task_workers_idle gauge\n"
               . "task_workers_idle {$stats['task_workers_idle']}\n"
               . "# TYPE user_workers_total gauge\n"
               . "user_workers_total {$stats['user_workers_total']}\n"
               . "# TYPE dispatch_total gauge\n"
               . "dispatch_total {$stats['dispatch_total']}\n"
               . "# TYPE connections_accepted gauge\n"
               . "connections_accepted {$stats['connections_accepted']}\n"
               . "# TYPE connections_active gauge\n"
               . "connections_active {$stats['connections_active']}\n"
               . "# TYPE connections_closed gauge\n"
               . "connections_closed {$stats['connections_closed']}\n"
               . "# TYPE reload_count gauge\n"
               . "reload_count {$stats['reload_count']}\n"
               . "# TYPE reload_last_time gauge\n"
               . "reload_last_time {$stats['reload_last_time']}\n"
               . "# TYPE worker_vm_object_num gauge\n"
               . "worker_vm_object_num {$stats['worker_vm_object_num']}\n"
               . "# TYPE worker_vm_resource_num gauge\n"
               . "worker_vm_resource_num {$stats['worker_vm_resource_num']}\n"
               . "# TYPE worker_memory_usage gauge\n"
               . "worker_memory_usage {$stats['worker_memory_usage']}\n{$event_workers}{$task_workers}{$user_workers}"
               . '# EOF';
    }
}