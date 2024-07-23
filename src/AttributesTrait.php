<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

trait AttributesTrait
{
    protected array $attributes = [];
    
    public function setAttribute(string $key, string|bool|int|float|null $value): static
    {
        $this->attributes[$key]     = $value;
        
        return $this;
    }
    
    public function getAttribute(string $key): string|bool|int|float|null
    {
        return $this->attributes[$key] ?? null;
    }
    
    public function getAttributes(): array
    {
        return $this->attributes;
    }
    
    public function setAttributes(array $attributes): static
    {
        $this->attributes           = $attributes;
        
        return $this;
    }
    
    public function addAttributes(array $attributes): static
    {
        $this->attributes           = array_merge($this->attributes, $attributes);
        
        return $this;
    }
    
    public function hasAttribute(string $key): bool
    {
        return isset($this->attributes[$key]);
    }
    
    public function findByPrefix(string $prefix): array
    {
        $prefix                     = $prefix.'.';
        
        $result                     = [];
        
        foreach($this->attributes as $key => $value) {
            if(str_starts_with($key, $prefix)) {
                $result[$key]       = $value;
            }
        }
        
        return $result;
    }
    
    public function findByPrefixFirst(string $prefix): ?string
    {
        $prefix                     = $prefix.'.';
        
        foreach($this->attributes as $key => $value) {
            if(str_starts_with($key, $prefix)) {
                return $value;
            }
        }
        
        return null;
    }
}