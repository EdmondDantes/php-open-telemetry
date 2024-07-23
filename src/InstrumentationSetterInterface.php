<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

interface InstrumentationSetterInterface extends InstrumentationAwareInterface
{
    public function setInstrumentationScope(InstrumentationScopeInterface $instrumentationScope): void;
}