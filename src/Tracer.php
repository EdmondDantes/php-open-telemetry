<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

use IfCastle\OpenTelemetry\Exceptions\ExporterException;
use IfCastle\OpenTelemetry\Protocol\TraceTemporaryStorageInterface;
use Psr\Log\LogLevel;

class Tracer            implements TracerInterface, InjectInterface, FreeInterface
{
    use InjectorTrait;
    use AnnotationsTrait;
    
    #[Dependency]
    protected SystemEnvironmentInterface $systemEnvironment;
    #[Dependency]
    protected TraceExporterInterface  $traceExporter;
    #[Dependency]
    protected ?TraceExporterInterface $traceCriticalExporter;
    
    #[Dependency]
    protected ?ScheduleTimerInterface $scheduleTimer;
    
    #[FromConfig(Config::S_SERVER)]
    protected array $serverConfig;
    
    #[FromConfig('trace-collector')]
    protected array $config;
    
    /**
     * If true, all logs will be sent as SpanEvents.
     * (need for Jaeger. because they don't support OpenTelemetry Log concept)
     * @var bool
     */
    protected bool $populateLogsAsSpanEvents = false;
    /**
     * Log structure by OpenTelemetry standard:
     * *-- ResourceLogs
     *    |------ InstrumentationLogs
     *           |--------- LogRecords
     *
     * We use spl_object_id() as the key to the ResourceLogs array.
     *
     * @var array <int, array<int, LogRecord>>
     */
    protected array $logs           = [];
    protected array $spans          = [];
    protected ?\Throwable $lastFlushError = null;
    protected int $maxLogRecords    = 1024;
    protected int $maxSpans         = 1024;
    /**
     * If true, then we will flush data after each trace/request.
     * @var bool
     */
    protected bool $flushByTrace    = true;
    protected bool $cleanByCount    = false;
    protected int $lastMemoryUsage  = 0;
    /**
     * Critical memory delta in bytes.
     *
     * @var int
     */
    protected int  $flushMemoryDelta = 1024 * 1024 * 100;
    protected int $criticalMemoryDelta = 1024 * 1024 * 500;
    protected bool $isCriticalCalled = false;
    protected array $instrumentationScopes = [];
    protected InstrumentationScopeInterface $selfInstrumentationScope;
    protected Trace $selfTrace;
    protected ResourceInterface $systemResource;
    protected ?PeriodicCallingInterface $flushStrategy = null;
    protected float $flushInterval  = 60.0;
    
    public function initializeAfterInject(): void
    {
        // Create self instrumentation scope
        $this->selfInstrumentationScope = new InstrumentationScope('tracer');
        $this->instrumentationScopes['i'.spl_object_id($this->selfInstrumentationScope)] = $this->selfInstrumentationScope;
        
        // Build system resource
        $attributes                 = [
            'service.name'          => 'api',
            'telemetry.sdk.name'    => 'opentelemetry',
            'telemetry.sdk.language' => 'php',
            'telemetry.sdk.version' => '1.23.1',
            'host.name'             => php_uname('n'),
            'host.arch'             => php_uname('m'),
            'os.type'               => strtolower(PHP_OS_FAMILY),
            'os.description'        => php_uname('r'),
            'os.name'               => PHP_OS,
            'os.version'            => php_uname('v'),
            'process.runtime.name'  => php_sapi_name(),
            'process.runtime.version' => PHP_VERSION,
            'process.runtime.description' => '',
            'process.pid'           => getmypid(),
            'process.executable.path' => PHP_BINARY
        ];
        
        //
        // Add information about Docker container
        //
        $containerId                = getenv('CONTAINER_ID');
        $containerName              = getenv('CONTAINER_NAME');
        $containerImageName         = getenv('CONTAINER_IMAGE_NAME');
        
        if(!empty($containerId)) {
            $attributes['container.id']         = $containerId;
        }
        
        if(!empty($containerName)) {
            $attributes['container.name']       = $containerName;
        }
        
        if(!empty($containerImageName)) {
            $attributes['container.image.name'] = $containerImageName;
        }
        
        if(!empty($this->serverConfig['api_name'])) {
            $attributes['service.name']         = $this->serverConfig['api_name'];
        }
        
        if(!empty($this->serverConfig['api_version'])) {
            $attributes['service.version']      = $this->serverConfig['api_version'];
        }
        
        // Add Jit information
        if (function_exists('opcache_get_status')) {
            $opcacheStatus          = opcache_get_status(false);
            
            if($opcacheStatus !== false) {
                $attributes['process.runtime.jit.enabled']      = $opcacheStatus['jit']['enabled'];
                $attributes['process.runtime.jit.on']           = $opcacheStatus['jit']['on'];
                $attributes['process.runtime.jit.opt_level']    = $opcacheStatus['jit']['opt_level'];
            }
        }
        
        if ($_SERVER['argv'] ?? null) {
            $attributes['process.command']      = $_SERVER['argv'][0];
            $attributes['process.command_args'] = $_SERVER['argv'];
        }
        
        if (extension_loaded('posix') && ($user = \posix_getpwuid(\posix_geteuid())) !== false) {
            $attributes['process.owner'] = $user['name'];
        }
        
        // Add information about Engine
        $attributes['engine.name']      = $this->systemEnvironment->getEngine();
        $attributes['engine.mode']      = $this->systemEnvironment->getExecutionMode();
        $attributes['engine.roles']     = $this->systemEnvironment->getExecutionRoles();

        // Server information
        if($this->systemEnvironment->hasDependency(StatefulServerInterface::STATEFUL_SERVER)) {
            $server                     = $this->systemEnvironment->getDependency(StatefulServerInterface::STATEFUL_SERVER);
            $attributes['engine.name']  = $server->getServerName();
            $attributes['engine.version'] = $server->getServerVersion();
            $attributes['engine.futures'] = $server->getServerFutures();
        }
        
        $this->systemResource       = new Resource('api', $attributes, 'https://opentelemetry.io/schemas/1.23.1');
        
        if(!empty($this->config['max_logs'])) {
            $this->maxLogRecords    = (int) $this->config['max_logs'];
        }
        
        if(!empty($this->config['max_spans'])) {
            $this->maxSpans         = (int) $this->config['max_spans'];
        }
        
        if(!empty($this->config['clean_by_count'])) {
            $this->cleanByCount     = (bool) $this->config['clean_by_count'];
        }
        
        if(!empty($this->config['flush_memory_delta'])) {
            $this->flushMemoryDelta = (int) $this->config['flush_memory_delta'];
        }
        
        if(!empty($this->config['critical_memory_delta'])) {
            $this->criticalMemoryDelta = (int) $this->config['critical_memory_delta'];
        }
        
        if(array_key_exists('populate_logs_as_span_events', $this->config)) {
            $this->populateLogsAsSpanEvents = (bool) $this->config['populate_logs_as_span_events'];
        }
        
        // This is trace never will be destroyed
        $this->selfTrace            = new Trace($this->systemResource);
        
        $this->systemEnvironment->getWorkerContext()?->addStartHandler($this->workerStarted(...));
    }
    
    /**
     * Handle flush throwable and return true if we need to restore data.
     *
     * @param \Throwable $throwable
     *
     * @return bool
     */
    protected function handleFlushThrowable(\Throwable $throwable): bool
    {
        $this->lastFlushError       = $throwable;
        
        if ($this->selfTrace->getCurrentSpan() === null) {
            $this->selfTrace->createSpan(
                'tracer->flushTelemetry',
                SpanKindEnum::INTERNAL,
                $this->selfInstrumentationScope
            );
        }
        
        $this->selfTrace->getCurrentSpan()?->recordException($throwable);
        
        $noRestore                  = false;
        
        if ($throwable instanceof ExporterException) {
            $noRestore              = $throwable->isExported()
                                      || $throwable->isPartialSuccess()
                                      || $throwable->isSerializationError();
        }
        
        return !$noRestore;
    }
    
    protected function workerStarted(): void
    {
        $workerContext          = $this->systemEnvironment->getWorkerContext();
        $this->lastMemoryUsage  = $workerContext->getMemoryUsage();
        
        // Configure system resource if it's a worker
        $resource               = $this->systemResource;
        
        $resource->setName('Worker '.$workerContext->getWorkerType()->value);
        
        $resource->addAttributes([
            'worker.id'         => $workerContext->getWorkerId(),
            'worker.type'       => $workerContext->getWorkerType()->value,
            'process.pid'       => getmypid(),
        ]);
        
        if($this->scheduleTimer === null) {
            return;
        }
        
        if(empty($this->config['flush_in_request_worker'])) {
            return;
        }
        
        $this->flushByTrace         = false;
        
        $flushInterval              = $this->config['flush_interval'] ?? 60.0;
        $flushInterval              = (float) $flushInterval;
        
        if ($flushInterval < 10.0) {
            $flushInterval          = 10.0;
        }
        
        $this->flushInterval        = $flushInterval;
        
        // Configure flush strategy
        $this->flushStrategy        = new PeriodicCallingWithDelayOnError(
            null,
            $this->config['delay_progression'] ?? 2.0,
            $this->config['max_delay'] ?? 15 * 60,
            $this->config['tolerance'] ?? 1
        );
        
        // It's a stateful server, so we need to flush data periodically
        $this->scheduleTimer->interval($flushInterval, $this->flushByTimer(...));
    }
    
    public function getResource(): ResourceInterface
    {
        return $this->systemResource;
    }
    
    public function newTelemetryContext(): TelemetryContextInterface
    {
        return new TelemetryContext($this);
    }
    
    public function createTrace(): TraceInterface
    {
        return new Trace($this->systemResource);
    }
    
    public function endTrace(TraceInterface $trace): void
    {
        $this->instrumentationScopes = array_merge($this->instrumentationScopes, $trace->getInstrumentationScopes());
        $this->spans                = array_merge_recursive($this->spans, $trace->getSpansByInstrumentationScope());
        $this->flushTrace();
    }
    
    public function registerLog(InstrumentationScopeInterface    $instrumentationScope,
                                string                           $level,
                                float|array|bool|int|string|null $body,
                                array                            $attributes = []
    ): void
    {
        // ALGORITHM:
        // We place telemetry data into a preliminary container conforming to the OpenTelemetry standard
        // but do not serialize the data to save processor time.
        // The data will be serialized later, at the time of transmission,
        // in the background, and will not impact the execution of the request.
        
        $telemetryContext           = $this->defineTelemetryContext();
        
        if($this->populateLogsAsSpanEvents) {
            $span                   = $telemetryContext->getCurrentTrace()?->getCurrentSpan();
            
            // If we have no current span, then we need to create a new span
            if($span === null) {
                $span               = $this->createSpan('log', SpanKindEnum::INTERNAL, $instrumentationScope, $attributes);
            }
            
            $name                   = $level;
            $attributes['log.level'] = $level;
            
            if(!empty($attributes['log.subject'])) {
                $name               = $attributes['log.subject'];
                $attributes['log.report'] = $body;
            } elseif (!empty($attributes['exception.message'])) {
                $name               = $attributes['exception.message'];
                $attributes['log.report'] = $body;
            } elseif (is_string($body)) {
                $name               = $body;
            }
            
            $span->addEvent($name, $attributes, SystemClock::now());
            return;
        }
        
        $logRecord                  = new Log(
            SystemClock::now(),
            $level,
            $body,
            $attributes,
            $telemetryContext->getTraceId(),
            $telemetryContext->getSpanId(),
            $telemetryContext->getTraceFlags()
        );
        
        // Collect logs in to structure:
        //
        // [*] ResourceLogs
        //     |- InstrumentationLogs
        //            |- LogRecords
        //
        
        $instrumentationScopeId     = 'i'.spl_object_id($instrumentationScope);
        
        // Remember the InstrumentationScope for future use
        if(false === array_key_exists($instrumentationScopeId, $this->instrumentationScopes)) {
            $this->instrumentationScopes[$instrumentationScopeId] = $instrumentationScope;
        }
        
        // So LogRecords are grouped by Resource (like /some/url/) and InstrumentationScope (like DataBase, HttpClient, Service).
        // And inherited from the current Span or Trace ResourceInfo.
        // So all logs for REST API request (or RPC, or WorkerRequest) will be grouped by ResourceInfo of the request.
        
        //
        // [*] TraceContext
        //     |- Trace
        //         |- ResourceInfo (like: /v2/endpoint/parameter/)
        //         |- SpanCollection (save to DB data, send email, etc.)
        //     |- Logs
        //         |- ResourceInfo (like: /v2/endpoint/parameter/)
        //              |- InstrumentationScope (like: database, email, etc.)
        //                  |- LogRecords.
        //
        // LogRecords can reference to TraceId and SpanId.
        //

        // All metrics will be grouped by ResourceInfo and InstrumentationScope.
        // But ResourceInfo is the same for all metrics
        
        if(false === array_key_exists($instrumentationScopeId, $this->instrumentationScopes)) {
            $this->logs[$instrumentationScopeId] = [];
        }
        
        $this->logs[$instrumentationScopeId][] = $logRecord;
    }
    
    public function registerException(\Throwable $throwable, array $attributes = []): void
    {
        $trace                      = $this->defineTelemetryContext()->getCurrentTrace();
        
        if($trace === null) {
            return;
        }
        
        $trace->getCurrentSpan()?->recordException($throwable, $attributes);
    }
    
    public function createSpan(string $spanName, SpanKindEnum $spanKind, InstrumentationScopeInterface $instrumentationScope = null, array $attributes = []): SpanInterface
    {
        $trace                      = $this->defineTelemetryContext()->getCurrentTrace() ?? $this->defineTrace();
        return $trace->createSpan($spanName, $spanKind, $instrumentationScope, $attributes);
    }
    
    public function endSpan(SpanInterface $span = null): void
    {
        $this->defineTelemetryContext()?->getCurrentTrace()?->endSpan($span);
    }
    
    public function flushTrace(): void
    {
        if($this->logs === [] && $this->spans === []) {
            return;
        }
        
        if($this->flushByTrace) {
            $this->flushTelemetry();
        }
        
        // If we have data to flush, and the flush strategy is defined,
        // and we are not called the flush strategy for a long time, then call it just now
        if($this->flushStrategy !== null
            && time() - $this->flushStrategy->getLastCalledAt() > $this->flushInterval
            && $this->flushStrategy->canBeInvoked()) {
            $this->flushByTimer();
        } elseif($this->flushStrategy !== null
                 && $this->flushStrategy->getErrorCount() > 1
                 && $this->lastMemoryUsage + $this->criticalMemoryDelta < $this->systemEnvironment->getWorkerContext()->getMemoryUsage()) {
            
            // Memory issues we need to flush data immediately
            if($this->systemEnvironment->isDeveloperMode()) {
                $megaBytes          = round(($this->systemEnvironment->getWorkerContext()->getMemoryUsage() - $this->lastMemoryUsage) / 1024 / 1024, 2);
                echo 'Detected critical memory usage. Memory delta '.$megaBytes.'. Telemetry will be destroyed!'.PHP_EOL;
            }

            $this->cleanTelemetry();
            
            $this->lastMemoryUsage = $this->systemEnvironment->getWorkerContext()->getMemoryUsage();
        }
    }
    
    public function flushTelemetry(bool $isCritical = false): void
    {
        $this->lastFlushError       = null;
        
        // Not allow flushing telemetry data twice at the same time
        if($isCritical && $this->isCriticalCalled) {
            return;
        }
        
        if($isCritical) {
            $this->isCriticalCalled = true;
        }
        
        $traceExporter              = $isCritical ? $this->traceCriticalExporter : $this->traceExporter;
        
        // If trace exporter is not defined, then we can't export telemetry data
        // clear the data and return
        if($traceExporter === null) {
            
            $this->cleanTelemetry();
            
            if($isCritical) {
                $this->isCriticalCalled = false;
            }
            
            return;
        }
        
        $logs                       = $this->logs;
        $spans                      = $this->spans;
        $scopes                     = $this->instrumentationScopes;
        $selfIndex                  = 'i'.spl_object_id($this->selfInstrumentationScope);
        
        $spans                      = array_merge_recursive($spans, $this->selfTrace->getSpansByInstrumentationScope());
        $this->selfTrace->cleanSpans();
        
        // We don't create a new span for the flushTelemetry() method,
        // because we want to save on memory.
        // We will only create a SPAN when we need to post an error message.
        
        if(false === array_key_exists($selfIndex, $scopes)) {
            $scopes[$selfIndex]     = $this->selfInstrumentationScope;
        }
        
        $this->cleanTelemetry();
        
        try {
            $traceExporter->exportTraces($this->systemResource, $scopes, $spans);
            $this->lastFlushError   = null;
        } catch (\Throwable $throwable) {
            if(false === $isCritical && $this->handleFlushThrowable($throwable)) {
                $this->spans        = array_merge_recursive($this->spans, $spans);
                $this->instrumentationScopes = array_merge($this->instrumentationScopes, $scopes);
            }
        }
        
        try {
            $this->traceExporter->exportLogs($this->systemResource, $scopes, $logs);
        } catch (\Throwable $throwable) {
            if(false === $isCritical && $this->handleFlushThrowable($throwable)) {
                $this->logs         = array_merge_recursive($this->logs, $logs);
                $this->instrumentationScopes = array_merge($this->instrumentationScopes, $scopes);
            }
        }
        
        $this->selfTrace->getCurrentSpan()?->end();
        
        if($isCritical) {
            $this->isCriticalCalled = false;
        }
    }
    
    protected function flushByTimer(): void
    {
        if($this->logs === [] && $this->spans === []) {
            return;
        }
        
        // WARNING!
        // Timer always creates a new coroutine, but we don't need to be called twice at the same time.
        // So we need to check if we are already called.
        if(false === $this->flushStrategy->isInvoked()) {

            $this->flushStrategy->invoke($this->flushTelemetryAndThrow(...));

            if($this->flushStrategy->getErrorCount() === 0) {
                
                if($this->traceCriticalExporter instanceof TraceTemporaryStorageInterface && $this->traceCriticalExporter->finalize()) {
                    
                    // If we have a critical trace exporter, and last flush was successful,
                    // then we need to export all stored data from the temporary storage.
                    // In other words, the service that received telemetry is working again and can accept all our data!
                    ExportStoredTelemetryStrategy::executeSelfInWorker($this->systemEnvironment);
                }
                
                // Remember the last memory usage when all be OK
                $this->lastMemoryUsage = $this->systemEnvironment->getWorkerContext()->getMemoryUsage();
                
                return;
            }
        }
        
        $this->flushByMemoryUsage();
    }
    
    protected function flushByMemoryUsage(): void
    {
        // Is the same time we need to control memory usage,
        // We remember the last memory usage when all be OK
        // And if the memory usage is increased by more than the criticalMemoryDelta.
        // This algorithm is based on the assumption that your application does not leak memory.
        // Therefore, the only reason for increased memory usage is telemetry.
        if($this->lastMemoryUsage + $this->flushMemoryDelta < $this->systemEnvironment->getWorkerContext()->getMemoryUsage()) {

            if($this->systemEnvironment->isDeveloperMode()) {
                $megaBytes          = round(($this->systemEnvironment->getWorkerContext()->getMemoryUsage() - $this->lastMemoryUsage) / 1024 / 1024, 2);
                echo 'Detected critical memory usage. Memory delta '.$megaBytes.'!'.PHP_EOL;
            }
            
            // then we need to flush data immediately
            $this->flushTelemetry(true);
            
            // Update last memory usage
            $this->lastMemoryUsage = $this->systemEnvironment->getWorkerContext()->getMemoryUsage();
            
            return;
        }

        if(false === $this->cleanByCount) {
            return;
        }

        // Memory control for logs and spans
        // We are not allowed to store telemetry data in memory for a long time,
        // So we need to flush data if we have too many logs or spans
        $totalCount                 = 0;
        
        foreach ($this->logs as $logs) {
            $totalCount             += count($logs);
        }
        
        if($totalCount > $this->maxLogRecords) {
            $this->flushTelemetry(true);
            return;
        }
        
        $totalCount                 = 0;
        
        foreach ($this->spans as $spans) {
            $totalCount             += count($spans);
        }
        
        if($totalCount > $this->maxSpans) {
            $this->flushTelemetry(true);
        }
    }
    
    protected function flushTelemetryAndThrow(): void
    {
        $this->flushTelemetry();
        
        if($this->lastFlushError !== null) {
            throw $this->lastFlushError;
        }
    }
    
    public function free(): void
    {
        // Flush logs
        $this->flushTelemetry();
    }
    
    protected function defineTelemetryContext(): TelemetryContextInterface
    {
        // Support for Coroutine Engine
        $coroutineContext           = $this->systemEnvironment->getCoroutineContext();
        $requestEnvironment         = null;
        
        // First, try to get the context from the coroutine context if exists
        if($coroutineContext !== null) {
            $context                = $coroutineContext->getLocal(TelemetryContextInterface::TELEMETRY_CONTEXT);
            
            
            if($context !== null) {
                return $context;
            }
            
            // If not exists, try to find request environment inside the coroutine context
            $requestEnvironment     = $coroutineContext->getLocal(RequestEnvironmentInterface::REQUEST_ENVIRONMENT);
            
            if($requestEnvironment === null) {
                // If not exists, create new context
                $context            = $this->createTelemetryContext();
                $coroutineContext->set(TelemetryContextInterface::TELEMETRY_CONTEXT, $context);
                $coroutineContext->defer(fn() => $context->free());
                
                return $context;
            }
        }
        
        if($requestEnvironment === null) {
            $requestEnvironment     = $this->systemEnvironment->getRequestEnvironment();
        }
        
        $context                    = null;
        
        if($requestEnvironment?->hasDependency(TelemetryContextInterface::TELEMETRY_CONTEXT)) {
            $context                = $requestEnvironment->getDependency(TelemetryContextInterface::TELEMETRY_CONTEXT);
        } elseif ($this->systemEnvironment->hasDependency(TelemetryContextInterface::TELEMETRY_CONTEXT)) {
            $context                = $this->systemEnvironment->getDependency(TelemetryContextInterface::TELEMETRY_CONTEXT);
        }
        
        if($context === null) {
            $context                = $this->createTelemetryContext();
            
            if($requestEnvironment !== null) {
                $requestEnvironment->set(TelemetryContextInterface::TELEMETRY_CONTEXT, $context);
            } else {
                $this->systemEnvironment->set(TelemetryContextInterface::TELEMETRY_CONTEXT, $context);
            }
        }
        
        return $context;
    }
    
    protected function createTelemetryContext(): TelemetryContextInterface
    {
        return new TelemetryContext($this);
    }
    
    protected function defineTrace(): TraceInterface
    {
        return new Trace($this->systemResource);
    }
    
    protected function exceptionToLog(\Throwable $throwable): Log
    {
        return new Log(
            SystemClock::now(),
            LogLevel::ERROR,
            $throwable->getMessage(),
            ExceptionFormatter::buildAttributes($throwable),
            $this->selfTrace->getTraceId(),
            $this->selfTrace->getCurrentSpan()?->getSpanId(),
            TraceFlagsEnum::SAMPLED
        );
    }
    
    protected function cleanTelemetry(): void
    {
        $this->logs                 = [];
        $this->spans                = [];
        $this->instrumentationScopes = [];
    }
}