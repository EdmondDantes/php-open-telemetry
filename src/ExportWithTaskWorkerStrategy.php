<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

use IfCastle\Core\DI\AnnotationsTrait;
use IfCastle\Core\DI\Dependency;
use IfCastle\Core\DI\InjectInterface;
use IfCastle\Core\DI\InjectorTrait;
use IfCastle\Core\Environment\SystemEnvironmentInterface;
use IfCastle\Core\Services\ServiceManagerInterface;

final class ExportWithTaskWorkerStrategy implements InjectInterface
{
    public static function executeSelfInWorker(SystemEnvironmentInterface $systemEnvironment, string $endpoint, string $payload): void
    {
        $serviceManager             = $systemEnvironment->getDependency(ServiceManagerInterface::SERVICE_MANAGER);
        
        // Call self in worker
        if($serviceManager instanceof ServiceManagerInterface) {
            $serviceManager->executeClassMethodInWorker(self::class, 'exportTelemetry', [$endpoint, $payload]);
        }
    }
    
    use InjectorTrait;
    use AnnotationsTrait;
    
    #[Dependency]
    protected ?TraceExporterInterface $traceExporter;
    
    public function exportTelemetry(string $endpoint, string $payload): void
    {
        if($this->traceExporter === null) {
            return;
        }

        $this->traceExporter->deferredSendRawTelemetry($endpoint, $payload);
    }
}
