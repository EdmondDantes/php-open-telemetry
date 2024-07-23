<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry\Protocol;

use IfCastle\Core\StatefulServers\Swoole\Swoole;

final class TransportFactory
{
    public static function getTransport(): TransportInterface
    {
        // Use grpc if grpc extension is loaded or swoole is supported
        if(extension_loaded('grpc') || Swoole::isCoroutine()) {
            return new GrpcTransport();
        } else {
            return new HttpTransport();
        }
    }
}