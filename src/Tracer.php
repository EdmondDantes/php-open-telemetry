<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

class Tracer                        implements TracerInterface
{
    /**
     * If true, all logs will be sent as SpanEvents.
     * (need for Jaeger. because they don't support the OpenTelemetry Log concept)
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
    protected array $instrumentationScopes = [];
    protected InstrumentationScopeInterface $selfInstrumentationScope;
    protected Trace $selfTrace;
    
    public function __construct(
        protected ResourceInterface $systemResource,
        protected TelemetryContextResolverInterface $telemetryContextResolver,
    )
    {
        // Create self instrumentation scope
        $this->selfInstrumentationScope = new InstrumentationScope('tracer');
        $this->instrumentationScopes['i'.spl_object_id($this->selfInstrumentationScope)] = $this->selfInstrumentationScope;
        $this->selfTrace            = new Trace($this->systemResource);
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
        $this->instrumentationScopes    = array_merge($this->instrumentationScopes, $trace->getInstrumentationScopes());
        $this->spans                    = array_merge_recursive($this->spans, $trace->getSpansByInstrumentationScope());
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
        // in the background and will not impact the execution of the request.
        
        $telemetryContext           = $this->telemetryContextResolver->resolveTelemetryContext();
        
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
        $trace                      = $this->telemetryContextResolver->resolveTelemetryContext()->getCurrentTrace();
        
        if($trace === null) {
            return;
        }
        
        $trace->getCurrentSpan()?->recordException($throwable, $attributes);
    }
    
    public function createSpan(
        string                        $spanName,
        SpanKindEnum                  $spanKind,
        InstrumentationScopeInterface $instrumentationScope = null,
        array                         $attributes = []
    ): SpanInterface
    {
        $trace                      = $this->telemetryContextResolver->resolveTelemetryContext()->getCurrentTrace() ?? $this->defineTrace();
        return $trace->createSpan($spanName, $spanKind, $instrumentationScope, $attributes);
    }
    
    public function endSpan(SpanInterface $span = null): void
    {
        $this->telemetryContextResolver->resolveTelemetryContext()->getCurrentTrace()?->endSpan($span);
    }
    
    public function cleanTelemetry(): void
    {
        $this->logs                 = [];
        $this->spans                = [];
        $this->instrumentationScopes = [];
    }
    
    protected function defineTrace(): TraceInterface
    {
        return new Trace($this->systemResource);
    }
}