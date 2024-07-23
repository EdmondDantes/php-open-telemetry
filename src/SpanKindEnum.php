<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

enum SpanKindEnum: int
{
    case UNSPECIFIED                = 0;
    case INTERNAL                   = 1;
    case SERVER                     = 2;
    case CLIENT                     = 3;
    case PRODUCER                   = 4;
    case CONSUMER                   = 5;
}
