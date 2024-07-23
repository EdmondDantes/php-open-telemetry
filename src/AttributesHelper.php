<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

use Opentelemetry\Proto\Common\V1\AnyValue;
use Opentelemetry\Proto\Common\V1\ArrayValue;
use Opentelemetry\Proto\Common\V1\KeyValue;
use Opentelemetry\Proto\Common\V1\KeyValueList;

class AttributesHelper
{
    /**
     * Save attributes to the OpenTelemetry element
     *
     * @param mixed $element
     * @param array $attributes
     *
     * @return void
     */
    public static function applyAttributes(mixed $element, array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $element->getAttributes()[] = (new KeyValue())
                ->setKey($key)
                ->setValue(self::convertAnyValue($value));
        }
        
        $element->setDroppedAttributesCount(0);
    }
    
    public static function convertAnyValue($value): AnyValue
    {
        $result = new AnyValue();
        if (is_array($value)) {
            if (self::isSimpleArray($value)) {
                $values = new ArrayValue();
                foreach ($value as $element) {
                    /** @psalm-suppress InvalidArgument */
                    $values->getValues()[] = self::convertAnyValue($element);
                }
                $result->setArrayValue($values);
            } else {
                $values = new KeyValueList();
                foreach ($value as $key => $element) {
                    /** @psalm-suppress InvalidArgument */
                    $values->getValues()[] = new KeyValue(['key' => $key, 'value' => self::convertAnyValue($element)]);
                }
                $result->setKvlistValue($values);
            }
        }
        if (is_int($value)) {
            $result->setIntValue($value);
        }
        if (is_bool($value)) {
            $result->setBoolValue($value);
        }
        if (is_float($value)) {
            $result->setDoubleValue($value);
        }
        if (is_string($value)) {
            $result->setStringValue($value);
        }
        
        return $result;
    }
    
    /**
     * Test whether an array is simple (non-KeyValue)
     */
    public static function isSimpleArray(array $value): bool
    {
        return $value === [] || array_key_first($value) === 0;
    }
}