<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

use IfCastle\Core\Config\FromConfig;
use IfCastle\Core\DI\AnnotationsTrait;
use IfCastle\Core\DI\Dependency;
use IfCastle\Core\DI\InjectInterface;
use IfCastle\Core\DI\InjectorTrait;
use IfCastle\Core\Environment\SystemEnvironmentInterface;
use IfCastle\Core\Exceptions\ErrorException;
use IfCastle\Core\StatefulServers\ScheduleTimerInterface;
use IfCastle\Core\Strategies\PeriodicExecution\PeriodicCallingInterface;
use IfCastle\Core\Strategies\PeriodicExecution\PeriodicCallingWithDelayOnError;
use IfCastle\OpenTelemetry\Exceptions\ExporterException;
use IfCastle\OpenTelemetry\Protocol\GrpcTransport;
use IfCastle\OpenTelemetry\Protocol\ProtobufSerializer;
use IfCastle\OpenTelemetry\Protocol\TransportFactory;
use IfCastle\OpenTelemetry\Protocol\TransportInterface;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceRequest;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceResponse;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use Opentelemetry\Proto\Common\V1\InstrumentationScope;
use Opentelemetry\Proto\Common\V1\KeyValue;
use Opentelemetry\Proto\Logs\V1\LogRecord;
use Opentelemetry\Proto\Logs\V1\ResourceLogs;
use Opentelemetry\Proto\Logs\V1\ScopeLogs;
use Opentelemetry\Proto\Resource\V1\Resource as Resource_;
use Opentelemetry\Proto\Trace\V1\ResourceSpans;
use Opentelemetry\Proto\Trace\V1\ScopeSpans;
use Opentelemetry\Proto\Trace\V1\Span;
use Opentelemetry\Proto\Trace\V1\Span\Event;
use Opentelemetry\Proto\Trace\V1\Span\Link;
use Opentelemetry\Proto\Trace\V1\Status;
use Swoole\Timer;

class TraceExporter                 implements TraceExporterInterface, InjectInterface
{
    use InjectorTrait;
    use AnnotationsTrait;
    
    #[Dependency]
    protected SystemEnvironmentInterface $systemEnvironment;
    
    #[FromConfig('trace-collector')]
    protected array $config;
    /**
     * Send data to the collector through a task
     * @var bool
     */
    protected bool $exportThroughTask = true;
    
    protected TransportInterface $transport;
    protected ProtobufSerializer $serializer;
    /**
     * Service for periodic calling when exporting transport is unavailable.
     * @var PeriodicCallingInterface|null
     */
    protected ?PeriodicCallingInterface $awaitAvailabilityStrategy = null;
    protected ?int $timerId          = null;
    protected array $buffer         = [];
    protected ?ExportTraceServiceRequest $bufferedTracesRequest = null;
    protected int $bufferedTracesSize = 0;
    protected ?ExportLogsServiceRequest $bufferedLogsRequest = null;
    protected int $requestPacketMaxSize = 1024 * 1024 * 16;
    protected int $bufferedLogsSize = 0;
    protected int $lastSendAt       = 0;
    protected int $sendInterval     = 0;
    protected int $awaitInterval    = 0;
    protected int $bufferSize       = 0;
    protected int $bufferMaxSize    = 0;
    protected bool $isSending       = false;
    protected bool $isCriticalFlushed = false;
    
    public function initializeAfterInject(): void
    {
        $this->defineTransport();
        $this->serializer           = ProtobufSerializer::forTransport($this->transport);
    }
    
    protected function defineTransport(): void
    {
        $this->transport            = TransportFactory::getTransport();
        $this->transport->setConfig($this->config);
        
        if($this->transport instanceof InjectInterface) {
            $this->transport->injectDependencies($this->systemEnvironment)->initializeAfterInject();
        }
        
        $this->exportThroughTask    = $this->config['export_through_task'] ?? true;
        $this->sendInterval         = $this->config['send_interval'] ?? 1;
        $this->awaitInterval        = $this->config['await_interval'] ?? 1;
        // 50 MB
        $this->bufferMaxSize        = $this->config['buffer_max_size'] ?? 1024 * 1024 * 50;

        if($this->sendInterval <= 0) {
            $this->sendInterval     = 1;
        }
        
        if($this->awaitInterval <= 0) {
            $this->awaitInterval    = 1;
        }
        
        // If we use GRPC transport, we don't need to send data delayed
        if($this->transport instanceof GrpcTransport) {
            $this->sendInterval    = 0;
        }
    }
    
    /**
     * Save attributes to the OpenTelemetry element
     *
     * @param mixed $element
     * @param array $attributes
     *
     * @return void
     */
    public static function applyAttributes(mixed $element, array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $element->getAttributes()[] = (new KeyValue())
                ->setKey($key)
                ->setValue(AttributesHelper::convertAnyValue($value));
        }
        
        $element->setDroppedAttributesCount(0);
    }
    
    /**
     * @throws ExporterException
     */
    public function exportTraces(ResourceInterface $resource, array $instrumentationScopes, array $spansByScope): void
    {
        if(count($spansByScope) === 0) {
            return;
        }
        
        try {
            $exportTraceServiceRequest  = new ExportTraceServiceRequest();
            
            $resourceSpans              = $this->convertResourceSpans($resource);
            
            $exportTraceServiceRequest->getResourceSpans()[] = $resourceSpans;
            
            foreach ($spansByScope as $instrumentationScopeId => $spans) {
                
                // Convert the instrumentation scope
                $instrumentationScope   = $instrumentationScopes[$instrumentationScopeId] ?? null;
                
                if ($instrumentationScope === null) {
                    continue;
                }
                
                $scopeSpans             = $this->convertScopeSpans($instrumentationScope);
                $resourceSpans->getScopeSpans()[] = $scopeSpans;
                $spanRecords            = $scopeSpans->getSpans();
                
                foreach ($spans as $span) {
                    $spanRecords[]      = $this->convertSpan($span);
                }
            }
            
            $payload                    = $this->serializer->serialize($exportTraceServiceRequest);
            
        } catch (\Throwable $exception) {
            throw (new ExporterException([
                'template'          => 'Error while serializing the trace {error}',
                'error'             => $exception->getMessage(),
            ], [], $exception))->markAsSerializationError();
        }
        
        $this->send(TransportInterface::ENDPOINT_TRACES, $payload);
    }
    
    public function exportLogs(ResourceInterface $resource, array $instrumentationScopes, array $logsByScope): void
    {
        if(count($logsByScope) === 0) {
            return;
        }
        
        try {
            $exportLogsServiceRequest   = new ExportLogsServiceRequest();
            $resourceLogs               = $this->convertResourceLogs($resource);
            $exportLogsServiceRequest->getResourceLogs()[] = $resourceLogs;
            
            foreach ($logsByScope as $instrumentationScopeId => $logs) {
                
                // Convert the instrumentation scope
                $instrumentationScope   = $instrumentationScopes[$instrumentationScopeId] ?? null;
                
                if ($instrumentationScope === null) {
                    continue;
                }
                
                $scopeLogs              = $this->convertInstrumentationScopeForLogs($instrumentationScope);
                $resourceLogs->getScopeLogs()[] = $scopeLogs;
                $logRecords             = $scopeLogs->getLogRecords();
                
                foreach ($logs as $log) {
                    
                    // Skip logs without traceId and spanId
                    if($log->traceId === null || $log->spanId === null) {
                        continue;
                    }
                    
                    $logRecords[]       = $this->convertLogRecord($log);
                }
            }
            
            $payload                    = $this->serializer->serialize($exportLogsServiceRequest);
            
        } catch (\Throwable $exception) {
            throw (new ExporterException([
                'template'          => 'Error while serializing the logs {error}',
                'error'             => $exception->getMessage(),
            ], [], $exception))->markAsSerializationError();
        }
        
        $this->send(TransportInterface::ENDPOINT_LOGS, $payload);
    }
    
    /**
     * Method for sending telemetry data to the collector through the task worker.
     * Method can be called only inside the task worker.
     *
     * @param string $endpoint
     * @param string $payload
     *
     * @return void
     * @throws ErrorException
     */
    public function deferredSendRawTelemetry(string $endpoint, string $payload): void
    {
        static $isLogger            = true;

        if($this->systemEnvironment->getWorkerContext()->isRequestWorker()) {
            throw new ErrorException('This method can be called only inside the task worker');
        } elseif(false === $isLogger) {

            $isLogger               = true;

            echo "Logger active in: ".getmypid()."\n";

            //$this->flushBufferToCritical();

            Timer::tick(1000, function () {
                echo "Sending ".($this->isSending ? 'TRUE' : 'FALSE').
                    " Await: ".($this->awaitAvailabilityStrategy !== null ? 'TRUE' : 'FALSE')
                    .". Buffer: ".count($this->buffer).". Size: $this->bufferSize\n";
            });
        }
        
        if($this->awaitAvailabilityStrategy !== null) {
            $this->mergeTelemetryAndPushToBufferSafe($endpoint, $payload);
            return;
        }
        
        $now                        = time();
        
        // Repack data if buffer is increased too much
        if(count($this->buffer) > 8) {
            $this->mergeTelemetryAndPushToBufferSafe($endpoint, $payload);
        } else {
            $this->buffer[]         = [$endpoint, $payload];
            $this->bufferSize       += strlen($payload);
        }
        
        if(false === $this->isSending && ($this->lastSendAt + $this->sendInterval < $now || $this->bufferSize >= $this->bufferMaxSize)) {
            $this->flushBuffer();
        } else if($this->bufferSize >= $this->bufferMaxSize) {
            $this->flushBufferToCritical();
        }
    }
    
    protected function mergeTelemetryAndPushToBufferSafe(string $endpoint, string $payload): void
    {
        try {
            $this->mergeTelemetryAndPushToBuffer($endpoint, $payload);
        } catch (\Throwable $exception) {
            if($this->systemEnvironment->isDeveloperMode()) {
                echo 'Repack error: '.$exception->getMessage().PHP_EOL;
            }
        }
    }
    
    /**
     * Method pushes telemetry data to the buffer and repacks many requests into one.
     * Each request has max size requestPacketMaxSize.
     *
     * @param string $endpoint
     * @param string $payload
     *
     * @return void
     * @throws \Exception
     */
    protected function mergeTelemetryAndPushToBuffer(string $endpoint, string $payload): void
    {
        // Repack packets and optimize by size
        if($endpoint === TransportInterface::ENDPOINT_TRACES) {
            
            if($this->bufferedTracesRequest === null) {
                $this->bufferedTracesRequest = new ExportTraceServiceRequest();
            }
            
            $this->bufferedTracesRequest->mergeFromString($payload);
            $this->bufferedTracesSize += strlen($payload);
            
            if($this->bufferedTracesSize >= $this->requestPacketMaxSize) {
                $this->buffer[]         = [$endpoint, $this->serializer->serialize($this->bufferedTracesRequest)];
                $this->bufferSize       += $this->bufferedTracesSize;
                $this->bufferedTracesRequest = null;
                $this->bufferedTracesSize = 0;
            }
            
        } elseif ($endpoint === TransportInterface::ENDPOINT_LOGS) {
            
            if($this->bufferedLogsRequest === null) {
                $this->bufferedLogsRequest = new ExportLogsServiceRequest();
            }
            
            $this->bufferedLogsRequest->mergeFromString($payload);
            $this->bufferedLogsSize += strlen($payload);
            
            if($this->bufferedLogsSize >= $this->requestPacketMaxSize) {
                $this->buffer[]         = [$endpoint, $this->serializer->serialize($this->bufferedLogsRequest)];
                $this->bufferSize       += $this->bufferedLogsSize;
                $this->bufferedLogsRequest = null;
                $this->bufferedLogsSize = 0;
            }
            
        } else {
            $this->buffer[]         = [$endpoint, $payload];
            $this->bufferSize       += strlen($payload);
        }
    }
    
    private function flushBuffer(): void
    {
        if($this->isSending) {
            return;
        }
        
        $this->isSending            = true;
        
        if($this->bufferedTracesRequest !== null) {
            $this->buffer[]         = [TransportInterface::ENDPOINT_TRACES, $this->serializer->serialize($this->bufferedTracesRequest)];
            $this->bufferSize       += $this->bufferedTracesSize;
            $this->bufferedTracesRequest = null;
            $this->bufferedTracesSize = 0;
        }
        
        if($this->bufferedLogsRequest !== null) {
            $this->buffer[]         = [TransportInterface::ENDPOINT_LOGS, $this->serializer->serialize($this->bufferedLogsRequest)];
            $this->bufferSize       += $this->bufferedLogsSize;
            $this->bufferedLogsRequest = null;
            $this->bufferedLogsSize = 0;
        }
        
        try {
            while (count($this->buffer) > 0) {
                [$endpoint, $payload]   = array_shift($this->buffer);
                $this->bufferSize       -= strlen($payload);
                
                try {
                    $this->handleResponse($this->transport->send($endpoint, $payload));
                } catch (\Throwable $exception) {
                    $this->pushToBufferWhenError($endpoint, $payload, $exception);
                    break;
                }
            }
        } finally {
            $this->isSending        = false;
        }
        
        $this->exportFromCriticalStorage();
    }
    
    private function pushToBufferWhenError(string $endpoint, string $payload, \Throwable $exception): void
    {
        if($this->systemEnvironment->isDeveloperMode()) {
            echo $exception->getMessage().PHP_EOL;
        }
        
        if($exception instanceof ExporterException || $payload === '') {
            return;
        }

        $this->buffer[]             = [$endpoint, $payload];
        $this->bufferSize           += strlen($payload);
        
        if($this->bufferSize >= $this->bufferMaxSize) {
            $this->flushBufferToCritical();
        }
        
        if($this->timerId !== null) {
            return;
        }
        
        $timer                      = $this->systemEnvironment->getDependency(ScheduleTimerInterface::SCHEDULE_TIMER);
        
        if($timer instanceof ScheduleTimerInterface) {
            $this->awaitAvailabilityStrategy = new PeriodicCallingWithDelayOnError($this->awaitAvailability(...));
            $this->timerId          = $timer->interval($this->awaitInterval, $this->awaitAvailabilityStrategy->invoke(...));
        }
    }
    
    private function flushBufferToCritical(): void
    {
        if($this->systemEnvironment->isDeveloperMode()) {
            echo 'Flush buffer to critical storage: buffer size = '.$this->bufferSize.PHP_EOL;
        }
        
        $buffer                     = $this->buffer;
        $this->buffer               = [];
        $this->bufferSize           = 0;
        $this->isCriticalFlushed    = true;
        
        try {
            
            $criticalExporter       = $this->systemEnvironment->getDependency(TraceExporterInterface::TRACE_CRITICAL_EXPORTER);
            
            if($criticalExporter === null) {
                return;
            }
            
            foreach ($buffer as $item) {
                [$endpoint, $payload]   = $item;
                
                $criticalExporter->deferredSendRawTelemetry($endpoint, $payload);
            }
        } catch (\Throwable) {
        }
    }
    
    private function awaitAvailability(): void
    {
        if(count($this->buffer) > 0) {
            [$endpoint, $payload]   = array_shift($this->buffer);
            $this->bufferSize       -= strlen($payload);
        } else {
            $endpoint               = TransportInterface::ENDPOINT_TRACES;
            // Empty payload
            $payload                = '';
        }
        
        try {
            $this->isSending        = true;
            $this->handleResponse($this->transport->send($endpoint, $payload));
        } catch (\Throwable $exception) {
            if(false === $exception instanceof ExporterException) {
                // Push to buffer again
                $this->buffer[]     = [$endpoint, $payload];
                $this->bufferSize   += strlen($payload);

                if($this->bufferSize >= $this->bufferMaxSize) {
                    $this->flushBufferToCritical();
                }

                throw $exception;
            }
        } finally {
            $this->isSending        = false;
        }

        $timer                      = $this->systemEnvironment->getDependency(ScheduleTimerInterface::SCHEDULE_TIMER);
        $timerId                    = $this->timerId;
        $this->timerId              = null;
        $this->awaitAvailabilityStrategy = null;

        if($timer instanceof ScheduleTimerInterface && $timerId !== null) {
            $timer->clear($timerId);
        }

        $this->flushBuffer();
    }
    
    private function exportFromCriticalStorage(): void
    {
        if(false === $this->isCriticalFlushed) {
            return;
        }
        
        if($this->systemEnvironment->isDeveloperMode()) {
            echo 'Export from critical storage'.PHP_EOL;
        }
        
        $this->isCriticalFlushed        = false;
        
        // Export stored telemetry
        $exportStoredTelemetryStrategy  = new ExportStoredTelemetryStrategy();
        $exportStoredTelemetryStrategy->injectDependencies($this->systemEnvironment)->initializeAfterInject();
        $exportStoredTelemetryStrategy->finalize();
        $exportStoredTelemetryStrategy->exportStoredTelemetry();
    }
    
    private function convertInstrumentationScopeForLogs(InstrumentationScopeInterface $instrumentationScope): ScopeLogs
    {
        $pScopeLogs                     = new ScopeLogs();
        $pInstrumentationScope          = new InstrumentationScope();
        
        $pInstrumentationScope->setName($instrumentationScope->getName());
        $pInstrumentationScope->setVersion((string) $instrumentationScope->getVersion());
        
        self::applyAttributes($pInstrumentationScope, $instrumentationScope->getAttributes());
        
        $pInstrumentationScope->setDroppedAttributesCount(0);
        
        $pScopeLogs->setScope($pInstrumentationScope);
        $pScopeLogs->setSchemaUrl($instrumentationScope->getSchemaUrl());
        
        return $pScopeLogs;
    }
    
    private function convertScopeSpans(InstrumentationScopeInterface $instrumentationScope): ScopeSpans
    {
        $pScopeSpans                = new ScopeSpans();
        
        $pInstrumentationScope      = new InstrumentationScope();
        $pInstrumentationScope->setName($instrumentationScope->getName());
        $pInstrumentationScope->setVersion((string) $instrumentationScope->getVersion());
        
        AttributesHelper::applyAttributes($pInstrumentationScope, $instrumentationScope->getAttributes());
        
        $pScopeSpans->setScope($pInstrumentationScope);
        $pScopeSpans->setSchemaUrl($instrumentationScope->getSchemaUrl());
        
        return $pScopeSpans;
    }
    
    private function convertSpan(SpanInterface $span): Span
    {
        $pSpan                      = new Span();
        
        $pSpan->setTraceId($this->serializer->serializeTraceId(hex2bin($span->getTraceId())));
        $pSpan->setSpanId($this->serializer->serializeSpanId(hex2bin($span->getSpanId())));
        $pSpan->setTraceState((string) $span->getTraceState());
        
        if ($span->getParentSpanId() !== null) {
            $pSpan->setParentSpanId($this->serializer->serializeSpanId(hex2bin($span->getParentSpanId())));
        }
        
        $pSpan->setName($span->getName());
        $pSpan->setKind($span->getSpanKind()->value);
        $pSpan->setStartTimeUnixNano($span->getStartTime());
        $pSpan->setEndTimeUnixNano($span->getEndTime());
        self::applyAttributes($pSpan, $span->getAttributes());
        
        foreach ($span->getEvents() as $event) {
            /** @psalm-suppress InvalidArgument */
            $pSpan->getEvents()[] = $pEvent = new Event();
            $pEvent->setTimeUnixNano($event->getTimeUnixNano());
            $pEvent->setName($event->getName());
            self::applyAttributes($pEvent, $event->getAttributes());
        }
        
        $pSpan->setDroppedEventsCount(0);
        
        foreach ($span->getLinks() as $link) {
            /** @psalm-suppress InvalidArgument */
            $pSpan->getLinks()[] = $pLink = new Link();
            $pLink->setTraceId($this->serializer->serializeTraceId(hex2bin($link->getTraceId())));
            $pLink->setSpanId($this->serializer->serializeSpanId(hex2bin($link->getSpanId())));
            //$pLink->setTraceState((string) $link->getSpanContext()->getTraceState());
            self::applyAttributes($pLink, $link->getAttributes());
        }
        
        $pSpan->setDroppedLinksCount(0);
        
        $pStatus                    = new Status();
        $pStatus->setMessage($span->getStatusDescription());
        $pStatus->setCode($span->getStatus()->value);
        $pSpan->setStatus($pStatus);
        
        return $pSpan;
    }
    
    private function convertResourceLogs(ResourceInterface $resource): ResourceLogs
    {
        $pResourceLogs              = new ResourceLogs();
        $pResource                  = new Resource_();
        self::applyAttributes($pResource, $resource->getAttributes());
        
        $pResource->setDroppedAttributesCount(0);
        $pResourceLogs->setResource($pResource);
        
        return $pResourceLogs;
    }
    
    protected function convertResourceSpans(ResourceInterface $resource): ResourceSpans
    {
        $pResourceSpans             = new ResourceSpans();
        $pResource                  = new Resource_();
        
        self::applyAttributes($pResource, $resource->getAttributes());
        $pResourceSpans->setResource($pResource);
        $pResourceSpans->setSchemaUrl($resource->getSchemaUrl());
        
        return $pResourceSpans;
    }
    
    private function convertLogRecord(Log $record): LogRecord
    {
        $pLogRecord             = new LogRecord();
        
        $pLogRecord->setBody(AttributesHelper::convertAnyValue($record->body));
        $pLogRecord->setTimeUnixNano($record->timeUnixNano);
        $pLogRecord->setObservedTimeUnixNano(0);
        
        $pLogRecord->setTraceId($this->serializer->serializeTraceId(hex2bin($record->traceId)));
        $pLogRecord->setSpanId($this->serializer->serializeSpanId(hex2bin($record->spanId)));
        
        if($record->flags !== null) {
            $pLogRecord->setFlags($record->flags->value);
        }
        
        $pLogRecord->setSeverityNumber($record->getSeverityNumber());
        $pLogRecord->setSeverityText($record->level);
        
        self::applyAttributes($pLogRecord, $record->attributes);
        
        $pLogRecord->setDroppedAttributesCount(0);
        
        return $pLogRecord;
    }
    
    protected function send(string $endpoint, string $payload): void
    {
        if($this->exportThroughTask && $this->systemEnvironment->getWorkerContext()->isRequestWorker()) {
            ExportWithTaskWorkerStrategy::executeSelfInWorker($this->systemEnvironment, $endpoint, $payload);
            return;
        }
        
        $response                   = $this->transport->send($endpoint, $payload);
        
        $this->handleResponse($response);
    }
    
    /**
     * @throws ExporterException
     * @throws \Exception
     */
    private function handleResponse(string $payload = null): void
    {
        if($payload === null) {
            return;
        }
        
        $serviceResponse            = new ExportLogsServiceResponse();
        
        try {
            $this->serializer->hydrate($serviceResponse, $payload);
        } catch (\Throwable $exception) {
            throw new ExporterException([
                'template'          => 'Error while deserializing the response {error}. Text: "{payload}"',
                'error'             => $exception->getMessage(),
                'payload'           => $payload
            ], [], $exception);
        }
        
        $partialSuccess             = $serviceResponse->getPartialSuccess();
        if ($partialSuccess !== null && $partialSuccess->getRejectedLogRecords()) {
            
            throw (new ExporterException([
                'template'          => 'Export partial success {rejected_logs}',
                'rejected_logs'     => $partialSuccess->getRejectedLogRecords(),
                'error_message'     => $partialSuccess->getErrorMessage(),
            ]))->markAsPartialSuccess();
        }
        
        if ($partialSuccess !== null && $partialSuccess->getErrorMessage()) {
            
            throw (new ExporterException([
                'template'          => 'Export success with warnings/suggestions {error_message}',
                'error_message'     => $partialSuccess->getErrorMessage(),
            ]))->markAsExported();
        }
    }
}