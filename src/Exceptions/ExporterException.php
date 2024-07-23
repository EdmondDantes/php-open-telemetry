<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry\Exceptions;

use IfCastle\Core\Exceptions\RuntimeException;

class ExporterException             extends RuntimeException
{
    public function markAsSerializationError(): static
    {
        $this->context['conversationError']  = true;
        
        return $this;
    }
    
    public function isSerializationError(): bool
    {
        return $this->context['conversationError'] ?? false;
    }
    
    public function markAsExported(): static
    {
        $this->context['exported']  = true;
        
        return $this;
    }
    
    public function isExported(): bool
    {
        return $this->context['exported'] ?? false;
    }
    
    public function markAsPartialSuccess(): static
    {
        $this->context['partialSuccess']  = true;
        
        return $this;
    }
    
    public function isPartialSuccess(): bool
    {
        return $this->context['partialSuccess'] ?? false;
    }
}