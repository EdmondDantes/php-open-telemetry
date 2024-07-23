<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

use IfCastle\Core\Exceptions\ErrorException;

class Trace                         implements TraceInterface
{
    protected static int    $counter = 0;
    protected static string $prefix  = '';
    protected static ?InstrumentationScope $nopeInstrumentationScope = null;
    
    public static function reset(): void
    {
        self::$prefix               = dechex(time()) . bin2hex(random_bytes(10));
        self::$counter              = 0;
    }
    
    public static function newTraceId(): string
    {
        // It's very fast to generate a UUID like based on the random bytes and the counter.
        // You should call IdGenerator::reset() method for each new process or worker.
        
        self::$counter++;
        
        if(self::$counter > 0xFFFF || self::$prefix === '') {
            self::reset();
        }
        
        return self::$prefix.str_pad(dechex(self::$counter), 4, '0', STR_PAD_LEFT);
    }
    
    protected bool $isExternal      = false;
    protected int $spanIdCounter    = 0;
    protected string $spanPrefix    = '';

    protected string $traceId;
    
    protected array $spanStack      = [];
    protected array $spanMap        = [];
    protected array $instrumentationScopeMap = [];
    
    public function __construct(protected ResourceInterface $resource, string $traceId = null)
    {
        $this->isExternal           = $traceId !== null;
        
        if(self::$nopeInstrumentationScope === null) {
            self::$nopeInstrumentationScope = new InstrumentationScope('nope');
        }
        
        $this->instrumentationScopeMap['i'.spl_object_id(self::$nopeInstrumentationScope)] = self::$nopeInstrumentationScope;
        
        $this->traceId              = $traceId ?? self::newTraceId();
        
        // External trace using span prefix from random bytes and time.
        if($traceId !== null) {
            $this->spanIdCounter    = 0;
            $this->spanPrefix       = bin2hex(random_bytes(7));
        }
    }
    
    public function newSpanId(): string
    {
        $id                         = dechex(++$this->spanIdCounter);

        // pad to 16 hex chars
        
        if($this->spanPrefix !== '') {
            return $this->spanPrefix.str_pad($id, 16 - strlen($this->spanPrefix), '0', STR_PAD_LEFT);
        }
        
        return str_pad($id, 16, '0', STR_PAD_LEFT);
    }
    
    public function getTraceId(): string
    {
        return $this->traceId;
    }
    
    public function isExternal(): bool
    {
        return $this->isExternal;
    }
    
    public function getCurrentSpanId(): ?string
    {
        return $this->getCurrentSpan()?->getSpanId();
    }
    
    public function getCurrentSpan(): ?SpanInterface
    {
        if(count($this->spanStack) === 0) {
            return null;
        }
        
        return $this->spanStack[count($this->spanStack) - 1];
    }
    
    public function getParentSpan(): ?SpanInterface
    {
        if(count($this->spanStack) < 2) {
            return null;
        }
        
        return $this->spanStack[count($this->spanStack) - 2];
    }
    
    public function getResource(): ResourceInterface
    {
        return $this->resource;
    }
    
    public function setResource(ResourceInterface $resource): static
    {
        $this->resource             = $resource;
        
        return $this;
    }
    
    public function findInstrumentationScopeId(InstrumentationScopeInterface $instrumentationScope): string
    {
        $instrumentationScopeId     = (string)spl_object_id($instrumentationScope);
        
        if(isset($this->instrumentationScopeMap[$instrumentationScopeId])) {
            return $instrumentationScopeId;
        }

        return '';
    }
    
    protected function findOrPutInstrumentationScope(InstrumentationScopeInterface $instrumentationScope): string
    {
        // We use prefix 'i' for array_merge() to not to lose the keys
        $instrumentationScopeId     = 'i'.spl_object_id($instrumentationScope);
        
        if(isset($this->instrumentationScopeMap[$instrumentationScopeId])) {
            return $instrumentationScopeId;
        }
        
        $this->instrumentationScopeMap[$instrumentationScopeId] = $instrumentationScope;
        
        return $instrumentationScopeId;
    }
    
    public function createSpan(string $spanName, SpanKindEnum $spanKind, InstrumentationScopeInterface $instrumentationScope = null, array $attributes = []): SpanInterface
    {
        $span                       = new Span($this, $spanName, $spanKind, $attributes);
        
        $this->spanStack[]          = $span;
        
        // Inherit InstrumentationScope from parent Span
        if($instrumentationScope === null) {
            $instrumentationScope   = $this->getParentSpan()?->getInstrumentationScope();
        }
        
        // Empty InstrumentationScope
        if($instrumentationScope === null) {
            $instrumentationScope   = self::$nopeInstrumentationScope;
        }
        
        $this->spanMap[$this->findOrPutInstrumentationScope($instrumentationScope)][] = $span;
        
        return $span;
    }
    
    public function endSpan(SpanInterface $span = null): void
    {
        if(count($this->spanStack) === 0) {
            $span->recordException(new ErrorException('Span stack is empty'));
            return;
        }
        
        if($span === null) {
            $span                   = $this->spanStack[count($this->spanStack) - 1];
        }
        
        if($this->spanStack[count($this->spanStack) - 1] !== $span) {
            $span->recordException(new ErrorException('Span stack last element is not the same as the span to end'));
            return;
        }

        array_pop($this->spanStack);
        
        $span->end();
    }
    
    public function getInstrumentationScopes(): array
    {
        return $this->instrumentationScopeMap;
    }
    
    public function getSpansByInstrumentationScope(): array
    {
        return $this->spanMap;
    }
    
    public function end(): void
    {
        $errors                     = [];
        
        try {
            while (count($this->spanStack) > 0) {
                $this->endSpan($this->spanStack[count($this->spanStack) - 1]);
            }
        } catch (\Throwable $throwable) {
            $errors[]               = $throwable;
        }
    }
    
    public function cleanSpans(): void
    {
        $this->spanStack            = [];
        $this->spanMap              = [];
    }
    
    public function free(): void
    {
        $this->spanStack            = [];
        $this->spanMap              = [];
        $this->instrumentationScopeMap = [];
    }
}