<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

use IfCastle\Core\DI\ContainerInterface;
use IfCastle\Core\DI\Dependency;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Logger                     extends Dependency
{
    public function __construct(
        protected ?string $name         = null,
        protected ?string $version      = null,
        protected ?string $schemaUrl    = null,
        protected array $attributes     = []
    )
    {
        parent::__construct(null, null, null, true);
    }
    
    public function getInitializer(): ?callable
    {
        return function(ContainerInterface $container, object $object, string $key, bool $isNullable): ?object
        {
            if(false === $container->hasDependency(TelemetryProviderInterface::TELEMETRY_PROVIDER)) {
                
                if($isNullable) {
                    return null;
                }
                
                // Empty telemetry provider
                return new \IfCastle\Logger\Logger();
            }
            
            $telemetryProvider      = $container->getDependency(TelemetryProviderInterface::TELEMETRY_PROVIDER);
            
            $name                   = $this->name;
            $version                = $this->version;
            $schemaUrl              = $this->schemaUrl;
            $attributes             = $this->attributes;
            
            if($name === null && $object instanceof InstrumentationAwareInterface) {
                $instrumentationScope   = $object->getInstrumentationScope();
                
                $name                   = $instrumentationScope->getName();
                $version                = $instrumentationScope->getVersion();
                $schemaUrl              = $instrumentationScope->getSchemaUrl();
                $attributes             = $instrumentationScope->getAttributes();
            }
            
            $instrumentationScope   = new InstrumentationScope(
                $name,
                $version,
                $schemaUrl,
                $attributes
            );
            
            return $telemetryProvider->provideLogger($instrumentationScope);
        };
    }
}