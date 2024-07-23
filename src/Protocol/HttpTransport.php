<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry\Protocol;

use IfCastle\Core\DI\AnnotationsTrait;
use IfCastle\Core\DI\InjectInterface;
use IfCastle\Core\DI\InjectorTrait;
use IfCastle\Core\Exceptions\ErrorException;
use IfCastle\Core\Services\HttpClient\HttpClient;
use IfCastle\Core\Services\HttpClient\HttpRequest;
use IfCastle\Core\Services\Service;

class HttpTransport             implements TransportInterface, InjectInterface
{
    use InjectorTrait;
    use AnnotationsTrait;
    
    #[Service]
    protected HttpClient $httpClient;
    
    protected array $config;
    protected string $endpoint;
    protected int $maxAttempts      = 0;
    
    /**
     * @throws ErrorException
     */
    public function setConfig(array $config): static
    {
        $this->config               = $config;
        
        $endpoint                   = $this->config['endpoint'];
        $this->maxAttempts          = $this->config['max_attempts'] ?? 0;
        
        if(empty($endpoint)) {
            throw new ErrorException('Telemetry endpoint is not configured: endpoint');
        }
        
        // Parse endpoint to host and port
        $endpoint                   = parse_url($endpoint);
        $host                       = $endpoint['host'] ?? null;
        $port                       = $endpoint['port'] ?? null;
        $protocol                   = $endpoint['scheme'] ?? null;
        
        if(empty($protocol)) {
            $protocol               = 'https';
        }
        
        if(empty($host)) {
            throw new ErrorException('Telemetry endpoint is not configured: host');
        }
        
        // 4318	HTTP	collector	accept OpenTelemetry Protocol (OTLP) over HTTP
        if(empty($port)) {
            $port                   = 4318;
        }
        
        $this->endpoint             = $protocol.'://'.$host.':'.$port;
        
        return $this;
    }
    
    public function contentType(): string
    {
        return TransportInterface::PROTOBUF;
    }
    
    public function send(string $endpoint, string $body): ?string
    {
        $endpoint                   = match ($endpoint) {
            // @see https://opentelemetry.io/docs/concepts/sdk-configuration/otlp-exporter-configuration/
            // Logs is not supported by Jaeger!
            TransportInterface::ENDPOINT_LOGS       => 'v1/logs',
            TransportInterface::ENDPOINT_TRACES     => 'v1/traces',
            TransportInterface::ENDPOINT_METRICS    => 'v1/metrics',
            default                 => throw new ErrorException('Unknown endpoint: '.$endpoint)
        };
        
        $request                    = new HttpRequest($this->endpoint.'/' . $endpoint);
        $request->setMethod(HttpRequest::METHOD_POST)
                ->setKeepAlive(120)
                ->setTimeout(60)
                ->setConnectionTimeout(5)
                ->setCompression(HttpRequest::COMPRESSION_GZIP);
        
        $request->addHeader('Content-Type', $this->contentType());
        $request->addHeader('Content-Length', (string)strlen($body));
        $request->setPayload($body);
        $request->withoutException();

        $response                   = $this->httpClient->request($request);

        if($response->getHttpException() !== null) {

            // It means that the server is OK, but the response is not
            if($response->getBody() !== '' && $response->getHttpCode() > 400 && $response->getHttpCode() < 500) {
                return $response->getBody();
            }

            throw $response->getHttpException();
        }
        
        return $response->getBody();
    }
}