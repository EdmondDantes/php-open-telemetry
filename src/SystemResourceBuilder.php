<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

class SystemResourceBuilder
{
    protected string $apiName       = '';
    protected string $apiVersion    = '';
    
    public function build(): ResourceInterface
    {
        // Build system resource
        $attributes                 = [
            'service.name'          => 'api',
            'telemetry.sdk.name'    => 'opentelemetry',
            'telemetry.sdk.language' => 'php',
            'telemetry.sdk.version' => '1.23.1',
            'host.name'             => php_uname('n'),
            'host.arch'             => php_uname('m'),
            'os.type'               => strtolower(PHP_OS_FAMILY),
            'os.description'        => php_uname('r'),
            'os.name'               => PHP_OS,
            'os.version'            => php_uname('v'),
            'process.runtime.name'  => php_sapi_name(),
            'process.runtime.version' => PHP_VERSION,
            'process.runtime.description' => '',
            'process.pid'           => getmypid(),
            'process.executable.path' => PHP_BINARY
        ];
        
        //
        // Add information about Docker container
        //
        $containerId                = getenv('CONTAINER_ID');
        $containerName              = getenv('CONTAINER_NAME');
        $containerImageName         = getenv('CONTAINER_IMAGE_NAME');
        
        if(!empty($containerId)) {
            $attributes['container.id']         = $containerId;
        }
        
        if(!empty($containerName)) {
            $attributes['container.name']       = $containerName;
        }
        
        if(!empty($containerImageName)) {
            $attributes['container.image.name'] = $containerImageName;
        }
        
        if($this->apiName !== '') {
            $attributes['service.name']         = $this->apiName;
        }
        
        if($this->apiVersion !== '') {
            $attributes['service.version']      = $this->apiVersion;
        }
        
        // Add Jit information
        if (function_exists('opcache_get_status')) {
            $opcacheStatus          = opcache_get_status(false);
            
            if($opcacheStatus !== false) {
                $attributes['process.runtime.jit.enabled']      = $opcacheStatus['jit']['enabled'];
                $attributes['process.runtime.jit.on']           = $opcacheStatus['jit']['on'];
                $attributes['process.runtime.jit.opt_level']    = $opcacheStatus['jit']['opt_level'];
            }
        }
        
        if ($_SERVER['argv'] ?? null) {
            $attributes['process.command']      = $_SERVER['argv'][0];
            $attributes['process.command_args'] = $_SERVER['argv'];
        }
        
        if (extension_loaded('posix') && ($user = \posix_getpwuid(\posix_geteuid())) !== false) {
            $attributes['process.owner'] = $user['name'];
        }

        $attributes                 = $this->buildCustomAttributes($attributes);
        
        return new Resource('api', $attributes, 'https://opentelemetry.io/schemas/1.23.1');
    }
    
    protected function buildCustomAttributes(array $attributes): array
    {
        return $attributes;
    }
}