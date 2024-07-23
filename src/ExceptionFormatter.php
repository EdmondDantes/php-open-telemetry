<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

final class ExceptionFormatter
{
    public function buildAttributes(\Throwable $throwable): array
    {
        // See https://opentelemetry.io/docs/specs/semconv/attributes-registry/exception/
        $attributes['exception.message']        = $throwable->getMessage();
        $attributes['exception.type']           = get_class($throwable);
        
        $seen                       = [];
        $trace                      = [];
        
        do {
            
            if(array_key_exists(spl_object_id($throwable), $seen)) {
                $trace[]            = '[CIRCULAR REFERENCE]';
                break;
            }
            
            if(count($seen) > 0) {
                $trace[]            = '[CAUSED BY] '.$throwable->getFile().'('.$throwable->getLine().'): '.get_class($throwable).'::'.$throwable->getMessage();
            }
            
            $seen[spl_object_id($throwable)] = $throwable;
            
            $isFirst                = true;
            
            // remove all arguments from trace
            foreach ($throwable->getTrace() as $item) {

                if(empty($item['file']) || empty($item['line'])) {
                    
                    if($isFirst) {
                        $isFirst        = false;
                        $item['file']   = $throwable->getFile();
                        $item['line']   = $throwable->getLine();
                    }
                }
                
                // Trace format
                // /path/to/your/script.php(10): YourClass->yourMethod()
                // or
                // /path/to/your/script.php(10): YourClass::yourMethod()
                // or
                // /path/to/your/script.php(10): your_function()

                if(empty($item['file']) && empty($item['line'])) {
                    
                    $item['line']       = '?';
                    
                    if(!empty($item['class'])) {
                        $item['file']   = $item['class'];
                        $item['class']  = '';
                        $item['function'] = $item['type'].$item['function'];
                    }
                    
                    continue;
                }
                
                // If class defined remove namespace:
                if(isset($item['class'])) {
                    $class          = strrchr($item['class'], '\\');
                    
                    if(is_string($class)) {
                        $item['class']  = substr($class, 1);
                    }
                    
                    $trace[]        = $item['file'] . '(' . $item['line'] . '): ' . $item['class'] . $item['type'] . $item['function'];
                } else {
                    $trace[]        = $item['file'] . '(' . $item['line'] . '): ' . $item['function'];
                }
            }
            
        } while ($throwable = $throwable->getPrevious());
        
        $attributes['exception.stacktrace']     = implode("\n", $trace);
        
        return $attributes;
    }
}