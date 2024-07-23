<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

class Span                          implements SpanInterface
{
    use ElementTrait;
    use AttributesTrait;
    use SpanElementTrait;
    
    protected ?\WeakReference $trace = null;
    protected SpanKindEnum $kind     = SpanKindEnum::INTERNAL;
    protected int $startTime         = 0;
    protected int $endTime           = 0;
    protected ?InstrumentationScopeInterface $instrumentationScope = null;
    protected StatusCodeEnum $status = StatusCodeEnum::STATUS_UNSET;
    protected string $statusDescription = '';
    protected bool   $hasEnded        = false;
    protected array  $events          = [];
    protected array $links           = [];
    protected TraceState $traceState;
    protected ExceptionFormatterInterface|null $exceptionFormatter = null;
    
    public function __construct(
        TraceInterface $trace,
        string $name,
        SpanKindEnum $kind          = null,
        array $attributes           = [],
        InstrumentationScopeInterface $instrumentationScope = null,
        ExceptionFormatterInterface $exceptionFormatter = null
    )
    {
        $this->trace                = \WeakReference::create($trace);
        $this->traceId              = $trace->getTraceId();
        $this->spanId               = $trace->newSpanId();
        $this->name                 = $name;
        $this->kind                 = $kind ?? SpanKindEnum::INTERNAL;
        $this->attributes           = $attributes;
        $this->instrumentationScope = $instrumentationScope;
        $this->exceptionFormatter   = $exceptionFormatter ?? new ExceptionFormatter;
        $this->traceState           = new TraceState();
        
        $this->startTime            = SystemClock::now();
    }
    
    protected function getTrace(): ?TraceInterface
    {
        return $this->trace?->get();
    }
    
    public function getParentSpanId(): ?string
    {
        return $this->getTrace()?->getParentSpan()?->getSpanId();
    }
    
    public function getTraceFlags(): TraceFlagsEnum
    {
        return TraceFlagsEnum::DEFAULT;
    }
    
    public function getSpanName(): string
    {
        return $this->name;
    }
    
    public function getSpanKind(): SpanKindEnum
    {
        return $this->kind;
    }
    
    public function getStartTime(): int
    {
        return $this->startTime;
    }
    
    public function getTimeUnixNano(): int
    {
        return $this->startTime;
    }
    
    public function getEndTime(): int
    {
        return $this->endTime;
    }
    
    public function getDuration(): int
    {
        return (int)ceil($this->getDurationNanos() / 1000000000);
    }
    
    public function getDurationNanos(): int
    {
        return $this->endTime - $this->startTime;
    }
    
    public function getTraceState(): TraceState
    {
        return $this->traceState;
    }
    
    public function getEvents(): array
    {
        return $this->events;
    }
    
    public function addEvent(string $name, iterable $attributes = [], int $timestamp = null): static
    {
        if($this->hasEnded) {
            return $this;
        }
        
        $this->events[]             = new Event($name, $attributes, $timestamp);
        
        return $this;
    }
    
    public function recordException(\Throwable $exception, array $attributes = []): static
    {
        if($this->hasEnded) {
            return $this;
        }
        
        // Automatically set status to ERROR
        $this->status               = StatusCodeEnum::STATUS_ERROR;
        
        if($attributes === []) {
            $attributes             = ExceptionFormatter::buildAttributes($exception);
        }
        
        $this->events[]             = new Event('exception', $attributes, SystemClock::now());
        
        return $this;
    }
    
    public function getStatus(): StatusCodeEnum
    {
        return $this->status;
    }
    
    public function getStatusDescription(): string
    {
        return $this->statusDescription;
    }
    
    public function setStatus(StatusCodeEnum $status, string $description = ''): static
    {
        if($this->hasEnded) {
            return $this;
        }
        
        $this->status               = $status;
        
        return $this;
    }
    
    public function isRecording(): bool
    {
        return false === $this->hasEnded;
    }
    
    public function hasEnded(): bool
    {
        return $this->hasEnded;
    }
    
    public function end(int $endEpochNanos = null): void
    {
        if($this->hasEnded) {
            return;
        }
        
        $this->endTime              = $endEpochNanos ?? SystemClock::now();
        $this->hasEnded             = true;
        
        if($this->status === StatusCodeEnum::STATUS_UNSET) {
            $this->status           = StatusCodeEnum::STATUS_OK;
        }
    }
    
    public function getLinks(): array
    {
        return $this->links;
    }
    
    public function addLink(LinkInterface $link): static
    {
        if ($this->hasEnded) {
            return $this;
        }
        
        $this->links[]              = $link;
        
        return $this;
    }
    
    public function getInstrumentationScope(): ?InstrumentationScopeInterface
    {
        return $this->instrumentationScope;
    }
}