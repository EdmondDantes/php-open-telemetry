<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

interface InstrumentationAwareInterface
{
    public function getInstrumentationScope(): InstrumentationScopeInterface;
}