<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

#[\Attribute(\Attribute::TARGET_CLASS)]
class InstrumentationScope          implements InstrumentationScopeInterface
{
    use ElementTrait;
    use AttributesTrait;
    
    protected ?string $version       = null;
    
    public function __construct(
        string      $name,
        ?string     $version    = null,
        string      $schemaUrl  = '',
        array       $attributes = []
    )
    {
        $this->name                 = $name;
        $this->version              = $version;
        $this->schemaUrl            = $schemaUrl;
        $this->attributes           = $attributes;
    }
    
    public function getVersion(): ?string
    {
        return $this->version;
    }
}