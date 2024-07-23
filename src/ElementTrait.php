<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

trait ElementTrait
{
    protected string $name          = '';
    protected string $schemaUrl     = '';
    
    public function getName(): string
    {
        return $this->name;
    }
    
    public function setName(string $name): static
    {
        $this->name                 = $name;
        
        return $this;
    }
    
    public function getSchemaUrl(): string
    {
        return $this->schemaUrl;
    }
    
    public function setSchemaUrl(string $schemaUrl): static
    {
        $this->schemaUrl            = $schemaUrl;
        
        return $this;
    }
}