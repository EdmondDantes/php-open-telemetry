<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

use IfCastle\Core\Config\FromConfig;
use IfCastle\Core\DI\AnnotationsTrait;
use IfCastle\Core\DI\ContainerInterface;
use IfCastle\Core\DI\Dependency;
use IfCastle\Core\DI\InjectInterface;
use IfCastle\Core\DI\InjectorTrait;
use IfCastle\Core\Environment\SystemEnvironmentInterface;
use IfCastle\Core\Services\ServiceManagerInterface;
use IfCastle\Core\StatefulServers\WatchdogInterface;
use IfCastle\OpenTelemetry\Exceptions\ExporterException;
use IfCastle\OpenTelemetry\Protocol\TraceTemporaryStorageInterface;
use IfCastle\OpenTelemetry\Protocol\TransportFactory;
use IfCastle\OpenTelemetry\Protocol\TransportInterface;

final class ExportStoredTelemetryStrategy implements InjectInterface
{
    public static function executeSelfInWorker(SystemEnvironmentInterface $systemEnvironment): void
    {
        $serviceManager             = $systemEnvironment->getDependency(ServiceManagerInterface::SERVICE_MANAGER);
        
        // Call self in worker
        if($serviceManager instanceof ServiceManagerInterface) {
            $serviceManager->executeClassMethodInWorker(self::class, 'exportStoredTelemetry', []);
        }
    }
    
    use InjectorTrait;
    use AnnotationsTrait;
    
    #[Dependency]
    protected ContainerInterface $container;
    
    #[FromConfig('trace-collector')]
    protected array $config;
    #[Dependency]
    protected ?TraceExporterInterface $traceCriticalExporter;
    
    #[Dependency]
    protected WatchdogInterface $watchdog;
    
    protected TransportInterface $transport;
    
    protected function defineTransport(): void
    {
        $this->transport            = TransportFactory::getTransport();
        $this->transport->setConfig(array_merge($this->config, ['max_attempts' => 3]));
        $this->transport->injectDependencies($this->container)->initializeAfterInject();
    }
    
    public function finalize(): void
    {
        if(false === $this->traceCriticalExporter instanceof TraceTemporaryStorageInterface) {
            return;
        }
        
        $this->traceCriticalExporter->finalize();
    }
    
    public function exportStoredTelemetry(): void
    {
        if(false === $this->traceCriticalExporter instanceof TraceTemporaryStorageInterface) {
            return;
        }
     
        $isDeveloperMode            = $this->container instanceof SystemEnvironmentInterface && $this->container->isDeveloperMode();
        
        $this->defineTransport();
        
        if($isDeveloperMode) {
            echo 'Exporting stored telemetry from critical storage...'.PHP_EOL;
        }
        
        try {
            
            foreach ($this->traceCriticalExporter->extractPackets() as [$endpoint, $payload]) {
                $this->transport->send($endpoint, $payload);
            }
            
            if($isDeveloperMode) {
                echo 'Stored telemetry from critical storage exported successfully.'.PHP_EOL;
            }
            
        } catch (\Throwable $exception) {
            
            if($isDeveloperMode) {
                echo 'Exception: '.$exception->getMessage().PHP_EOL;
            }
            
            $this->watchdog->ping();
            // If transport fails, we need to clear old packets to avoid memory leak on disk.
            $this->traceCriticalExporter->clearOldPackets();
        }
    }
}