<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

interface ElementInterface
{
    public function getName(): string;
    
    public function setName(string $name): static;
    
    public function getSchemaUrl(): string;
    
    public function setSchemaUrl(string $schemaUrl): static;
}