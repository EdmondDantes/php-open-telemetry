<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

use IfCastle\Core\Containers\ArraySerializableInterface;
use IfCastle\Core\Containers\SerializableInterface;
use IfCastle\Core\DI\Dependency;
use IfCastle\Core\DI\InjectInterface;
use IfCastle\Core\DI\InjectorTrait;
use IfCastle\Core\Environment\SystemEnvironmentInterface;
use IfCastle\Core\Exceptions\BaseException;
use IfCastle\Logger\Logger;

class TelemetryLogger               extends Logger
                                    implements  InjectInterface, SpanLoggerInterface
{
    use InjectorTrait;

    #[Dependency]
    protected SystemEnvironmentInterface $systemEnvironment;
    #[Dependency]
    protected TracerInterface            $tracer;
    protected SpanKindEnum               $spanKind = SpanKindEnum::INTERNAL;
    
    protected function _describeDependencies(): array
    {
        return [
            SystemEnvironmentInterface::SYSTEM_ENVIRONMENT => $this->_dependency(SystemEnvironmentInterface::SYSTEM_ENVIRONMENT),
            TracerInterface::TRACER => $this->_dependency(TracerInterface::TRACER)
        ];
    }
    
    public function defineSpanKind(SpanKindEnum $spanKind): static
    {
        $this->spanKind             = $spanKind;
        
        return $this;
    }
    
    public function startSpan(string $spanName, array $attributes = []): SpanInterface
    {
        return $this->tracer->createSpan($spanName, $this->spanKind, $this->instrumentationScope, $attributes);
    }
    
    public function endSpan(SpanInterface $span = null): void
    {
        $this->tracer->endSpan($span);
    }
    
    public function put(string $level, string $subject, $report = null, array $tags = [])
    {
        $isThrowable                = $report instanceof \Throwable;
        
        $attributes                 = [
            'log.subject'           => $subject
        ];
        
        if($isThrowable) {
            $attributes             = array_merge($attributes, ExceptionFormatter::buildAttributes($report));
            
            // We register the exception in the telemetry span-context and as log record
            // $this->tracer->registerException($report, $attributes);
        }
        
        if($report instanceof BaseException) {
            $report                 = $report->getArray(true);
        } elseif ($report instanceof \Throwable) {
            $report                 = BaseException::serializeToArray($report);
        }
        
        if(!is_array($report) && !is_scalar($report)) {
            
            try {
                if($report instanceof ArraySerializableInterface) {
                    $report             = $report->toArray();
                } elseif ($report instanceof SerializableInterface) {
                    $report             = $report->containerSerialize();
                } else {
                    $report             = '<<error serialize report data>>';
                }
            } catch (\Throwable $throwable) {
                $report                 = '<<error serialize report data>>:'.$throwable->getMessage().' '.$throwable->getFile().':'.$throwable->getLine();
            }
        }
        
        // Convert tags to attributes
        foreach ($tags as $tag) {
            $attributes['tag.'.$tag]    = true;
        }
        
        $this->tracer->registerLog($this->instrumentationScope, $level, $report, $attributes);
    }
}