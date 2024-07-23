<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

interface AttributesInterface
{
    public function setAttribute(string $key, string|bool|int|float|null $value): static;
    
    public function getAttribute(string $key): string|bool|int|float|null;
    
    public function getAttributes(): array;
    
    public function setAttributes(array $attributes): static;
    
    public function addAttributes(array $attributes): static;
    
    public function hasAttribute(string $key): bool;
    
    public function findByPrefix(string $prefix): array;
    
    public function findByPrefixFirst(string $prefix): ?string;
}