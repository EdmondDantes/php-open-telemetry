<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

use IfCastle\Core\Environment\Environment;
use IfCastle\Core\Exceptions\ErrorException;
use IfCastle\Core\Strategies\Handlers\FinalHandlersInterface;
use IfCastle\Core\Strategies\Handlers\FinalHandlersTrait;

class TelemetryContext              extends Environment
                                    implements TelemetryContextInterface, FinalHandlersInterface
{
    use FinalHandlersTrait;
    
    protected ?TraceInterface $trace        = null;
    protected ?\WeakReference $tracer       = null;
    
    public function __construct(TracerInterface $tracer, array $data = [], TelemetryContextInterface $parent = null)
    {
        parent::__construct(
            $data,
            $parent
        );
        
        $this->tracer                       = \WeakReference::create($tracer);
        $this->trace                        = $tracer->createTrace();
    }
    
    public function getCurrentTrace(): ?TraceInterface
    {
        return $this->trace;
    }
    
    public function getTraceId(): ?string
    {
        return $this->trace?->getTraceId();
    }
    
    public function getSpanId(): ?string
    {
        return $this->trace?->getCurrentSpanId();
    }
    
    public function getTraceFlags(): TraceFlagsEnum
    {
        return TraceFlagsEnum::SAMPLED;
    }
    
    public function free(): void
    {
        // First, execute final handlers
        $exceptions                 = $this->executeFinalHandlers();
        
        try {
            $this->trace?->end();
            
            if($this->trace !== null) {
                $this->tracer?->get()?->endTrace($this->trace);
            }
        } catch(\Throwable $exception) {
            $exceptions[]           = $exception;
            // ignore
        }
        
        $this->trace                = null;
        $this->tracer               = null;
        
        parent::free();
        
        if(!empty($exceptions)) {
            $this->throwErrors($exceptions, ['telemetry-context']);
        }
    }
}