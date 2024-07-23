<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

/**
 * For the semantics of status codes see
 * https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/trace/api.md#set-status
 *
 * Protobuf type <code>opentelemetry.proto.trace.v1.Status.StatusCode</code>
 */
enum StatusCodeEnum: int
{
    case STATUS_UNSET               = 0;
    case STATUS_OK                  = 1;
    case STATUS_ERROR               = 2;
}
