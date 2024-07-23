<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry\Protocol;

use IfCastle\Core\DI\AnnotationsTrait;
use IfCastle\Core\DI\InjectInterface;
use IfCastle\Core\DI\InjectorTrait;
use IfCastle\Core\Exceptions\ErrorException;
use IfCastle\Core\Services\HttpClient\GrpcRequest;
use IfCastle\Core\Services\HttpClient\HttpClient;
use IfCastle\Core\Services\HttpClient\HttpConnectionInterface;
use IfCastle\Core\Services\Service;

class GrpcTransport                 implements TransportInterface, InjectInterface
{
    use InjectorTrait;
    use AnnotationsTrait;
    
    #[Service]
    protected HttpClient $httpClient;
    
    protected ?HttpConnectionInterface $connection = null;
    
    protected array $config;
    
    protected string $endpoint;
    
    public function setConfig(array $config): static
    {
        $this->config               = $config;
        
        $endpoint                   = $this->config['endpoint'];
        if(empty($endpoint)) {
            throw new ErrorException('Telemetry endpoint is not configured: endpoint');
        }
        
        // Parse endpoint to host and port
        $endpoint                   = parse_url($endpoint);
        $host                       = $endpoint['host'] ?? null;
        $port                       = $endpoint['port'] ?? null;
        $scheme                     = $endpoint['scheme'] ?? 'http';

        if(empty($host)) {
            throw new ErrorException('Telemetry endpoint is not configured: host');
        }
        
        if(empty($port)) {
            $port                   = 4317;
        }
        
        $this->endpoint             = $scheme.'://'.$host.':'.$port;
        
        return $this;
    }
    
    public function contentType(): string
    {
        return TransportInterface::PROTOBUF;
    }
    
    public function send(string $endpoint, string $body): ?string
    {
        if($this->connection === null) {
            $this->connection();
        }

        $path                       = match ($endpoint) {
            TransportInterface::ENDPOINT_LOGS       => 'opentelemetry.proto.collector.logs.v1.LogsService/Export',
            TransportInterface::ENDPOINT_TRACES     => 'opentelemetry.proto.collector.trace.v1.TraceService/Export',
            TransportInterface::ENDPOINT_METRICS    => 'opentelemetry.proto.collector.metrics.v1.MetricsService/Export',
            default                 => throw new ErrorException('Unknown endpoint: '.$endpoint)
        };
        
        $request                    = new GrpcRequest($path, $body);
        $request->addHeader('User-Agent', 'kc-api-core/1.0.0');
        $request->withoutException();

        $response                   = $this->connection->request($request);

        if($response->getHttpException() !== null) {
            
            // It means that the server is OK, but the response is not
            if($response->getBody() !== '' && $response->getHttpCode() > 400 && $response->getHttpCode() < 500) {
                return $response->getBody();
            }
            
            throw $response->getHttpException();
        }
        
        return $response->getBody();
    }
    
    protected function connection(): void
    {
        $this->connection           = $this->httpClient->startGrpcConnection($this->endpoint);
    }
}