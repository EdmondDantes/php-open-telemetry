<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

interface InstrumentationScopeInterface extends ElementInterface, AttributesInterface
{
    public function getVersion(): ?string;
}