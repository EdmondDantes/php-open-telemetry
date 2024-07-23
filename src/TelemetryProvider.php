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
use IfCastle\Logger\LoggerInterface;
use IfCastle\OpenTelemetry\Metrics\MeterInterface;
use IfCastle\OpenTelemetry\Metrics\MeterProviderInterface;
use IfCastle\OpenTelemetry\Metrics\Nope\NopeProvider;
use IfCastle\OpenTelemetry\Metrics\StateInterface;

class TelemetryProvider             implements TelemetryProviderInterface, InjectInterface
{
    use InjectorTrait;
    use AnnotationsTrait;
    
    #[Dependency]
    protected SystemEnvironmentInterface $systemEnvironment;
    
    #[FromConfig('metrics-collector')]
    protected array $metricsConfig;
    
    protected MeterProviderInterface $meterProvider;
    
    /**
     * Logger classes by system type
     *
     * @var array
     */
    protected array $loggerClasses  = [];
    
    /**
     * @throws ErrorException
     */
    public function initializeAfterInject(): void
    {
        $loggerClasses              = $this->systemEnvironment->get(self::TELEMETRY_LOGGERS);
        
        if($loggerClasses === null) {
            $loggerClasses          = [self::DEFAULT => TelemetryLogger::class];
        }
        
        if(!is_array($loggerClasses)) {
            throw new ErrorException('Telemetry loggers must be an array');
        }
        
        $this->loggerClasses        = $loggerClasses;
        
        if(empty($this->metricsConfig['enabled'])) {
            $this->meterProvider    = new NopeProvider();
            return;
        }
        
        $provider                   = $this->metricsConfig['provider'] ?? null;
        
        if(empty($provider)) {
            $this->meterProvider    = new NopeProvider();
            return;
        }
        
        $this->meterProvider        = new $provider();
        
        if($this->meterProvider instanceof InjectInterface) {
            $this->meterProvider->injectDependencies($this->systemEnvironment)->initializeAfterInject();
        }
    }
    
    public function isMetricsEnabled(): bool
    {
        return $this->meterProvider instanceof NopeProvider === false;
    }
    
    public function getMeterProvider(): MeterProviderInterface
    {
        return $this->meterProvider;
    }
    
    /**
     * @throws ErrorException
     */
    public function provideLogger(InstrumentationScopeInterface $instrumentationScope): LoggerInterface
    {
        // 1. Define a system type
        $type                       = $instrumentationScope->getAttribute(self::TYPE_LOGGER);
        
        if($type !== null && isset($this->loggerClasses[$type])) {
            $loggerClass            = $this->loggerClasses[$type];
        } else {
            $parts                  = explode('.', $instrumentationScope->getName());
            
            $type                   = $parts[0];
            
            // 2. Get the logger class for the system type
            $loggerClass            = $this->loggerClasses[$type] ?? null;
            
            if($loggerClass === null) {
                $loggerClass        = $this->loggerClasses[self::DEFAULT] ?? null;
            }
        }
        
        if($loggerClass === null) {
            throw new ErrorException('No logger class found for system type: '.$type);
        }

        // 3. Create the logger
        $logger                     = new $loggerClass();
        
        if($logger instanceof InstrumentationSetterInterface) {
            $logger->setInstrumentationScope($instrumentationScope);
        }
        
        if($logger instanceof InjectInterface) {
            $logger->injectDependencies($this->systemEnvironment)->initializeAfterInject();
        }
        
        return $logger;
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
        return $this->meterProvider->registerCounter(
            $instrumentationScope,
            $name,
            $unit,
            $description,
            $attributes,
            $isReset
        );
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
        return $this->meterProvider->registerUpDownCounter(
            $instrumentationScope,
            $name,
            $unit,
            $description,
            $attributes,
            $isReset
        );
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
        return $this->meterProvider->registerGauge(
            $instrumentationScope,
            $name,
            $unit,
            $description,
            $attributes,
            $isReset
        );
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
        return $this->meterProvider->registerHistogram(
            $instrumentationScope,
            $name,
            $unit,
            $description,
            $attributes,
            $isReset
        );
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
        return $this->meterProvider->registerSummary(
            $instrumentationScope,
            $name,
            $unit,
            $description,
            $attributes,
            $isReset
        );
    }
    
    public function registerState(
        InstrumentationScopeInterface $instrumentationScope,
        string                        $name,
        ?string                       $description = null,
        array                         $attributes = [],
        bool                          $isReset = false
    ): StateInterface
    {
        return $this->meterProvider->registerState($instrumentationScope, $name, $description, $attributes, $isReset);
    }
}