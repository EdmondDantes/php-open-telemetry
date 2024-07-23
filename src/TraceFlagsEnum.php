<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

enum TraceFlagsEnum: int
{
    case DEFAULT                    = 0;
    case SAMPLED                    = 1;
}
