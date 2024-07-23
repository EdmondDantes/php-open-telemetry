<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry;

use IfCastle\Core\Exceptions\ErrorException;

/**
 * TraceState parses and stores the tracestate header as an immutable list of string
 * key/value pairs. It provides the following operations following the rules described
 * in the W3C Trace Context specification:
 *      - Get value for a given key
 *      - Add a new key/value pair
 *      - Update an existing value for a given key
 *      - Delete a key/value pair
 *
 * @see https://www.w3.org/TR/trace-context/#tracestate-header
 * @see https://github.com/open-telemetry/opentelemetry-specification/blob/master/specification/trace/api.md#tracestate
 */
class TraceState                    implements AttributesInterface
{
    public const MAX_LIST_MEMBERS       = 32; //@see https://www.w3.org/TR/trace-context/#tracestate-header-field-values
    public const MAX_COMBINED_LENGTH    = 512; //@see https://www.w3.org/TR/trace-context/#tracestate-limits
    public const LIST_MEMBERS_SEPARATOR = ',';
    public const LIST_MEMBER_KEY_VALUE_SPLITTER = '=';
    private const VALID_KEY_CHAR_RANGE = '[_0-9a-z-*\/]';
    private const VALID_KEY = '[a-z]' . self::VALID_KEY_CHAR_RANGE . '{0,255}';
    private const VALID_VENDOR_KEY = '[a-z0-9]' . self::VALID_KEY_CHAR_RANGE . '{0,240}@[a-z]' . self::VALID_KEY_CHAR_RANGE . '{0,13}';
    private const VALID_KEY_REGEX = '/^(?:' . self::VALID_KEY . '|' . self::VALID_VENDOR_KEY . ')$/';
    private const VALID_VALUE_BASE_REGEX = '/^[ -~]{0,255}[!-~]$/';
    private const INVALID_VALUE_COMMA_EQUAL_REGEX = '/,|=/';
    
    use AttributesTrait;
    
    public function __construct(string $rawTraceState = null)
    {
        if ($rawTraceState === null || trim($rawTraceState) === '') {
            return;
        }
        
        $this->attributes           = $this->parse($rawTraceState);
    }
    
    public function setAttributes(array $attributes): static
    {
        $this->validateKeyValues($attributes);
        
        $this->attributes           = $attributes;
        
        return $this;
    }
    
    public function addAttributes(array $attributes): static
    {
        $this->validateKeyValues($attributes);
        
        $this->attributes           = array_merge($this->attributes, $attributes);
        
        return $this;
    }
    
    /**
     * @throws ErrorException
     */
    protected function validateKeyValues(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            
            if($this->validateKey($key)) {
                throw new ErrorException([
                    'template'      => 'TraceState invalid key: "{key}"',
                    'key'           => $key
                ]);
            }
            
            if($this->validateValue($value)) {
                throw new ErrorException([
                    'template'      => 'TraceState invalid value: "{value}"',
                    'key'           => $value
                ]);
            }
            
        }
    }
    
    public function __toString(): string
    {
        if ($this->attributes === []) {
            return '';
        }
        $traceStateString='';
        foreach (array_reverse($this->attributes) as $k => $v) {
            $traceStateString .=$k . self::LIST_MEMBER_KEY_VALUE_SPLITTER . $v . self::LIST_MEMBERS_SEPARATOR;
        }
        
        return rtrim($traceStateString, ',');
    }
    
    /**
     * Parse the raw trace state header into the TraceState object. Since new or updated entries must
     * be added to the beginning of the list, the key-value pairs in the TraceState object will be
     * stored in reverse order. This ensures new entries added to the TraceState object are at the
     * beginning when we reverse the order back again while building the final tracestate header.
     *
     * Ex:
     *      tracestate = 'vendor1=value1,vendor2=value2'
     *
     *                              ||
     *                              \/
     *
     *      $this->tracestate = ['vendor2' => 'value2' ,'vendor1' => 'value1']
     *
     */
    private function parse(string $rawTraceState): array
    {
        if (strlen($rawTraceState) > self::MAX_COMBINED_LENGTH) {
            //self::logWarning('tracestate discarded, exceeds max combined length: ' . self::MAX_COMBINED_LENGTH);
            
            return [];
        }
        
        $parsedTraceState = [];
        
        $listMembers                = explode(self::LIST_MEMBERS_SEPARATOR, $rawTraceState);
        
        if (count($listMembers) > self::MAX_LIST_MEMBERS) {
            //self::logWarning('tracestate discarded, too many members');
            
            return [];
        }
        
        foreach ($listMembers as $listMember) {
            $vendor = explode(self::LIST_MEMBER_KEY_VALUE_SPLITTER, trim($listMember));
            
            // There should only be one list-member per vendor separated by '='
            if (count($vendor) !== 2 || !$this->validateKey($vendor[0]) || !$this->validateValue($vendor[1])) {
                //self::logWarning('tracestate discarded, invalid member: ' . $listMember);
                
                return [];
            }
            $parsedTraceState[$vendor[0]] = $vendor[1];
        }
        
        /*
         * Reversing the tracestate ensures the new entries added to the TraceState object are at
         * the beginning when we reverse it back during __toString().
        */
        return array_reverse($parsedTraceState);
    }
    
    /**
     * The Key is an opaque string that is an identifier for a vendor. It can be up
     * to 256 characters and MUST begin with a lowercase letter or a digit, and can
     * only contain lowercase letters (a-z), digits (0-9), underscores (_), dashes (-),
     * asterisks (*), and forward slashes (/). For multi-tenant vendor scenarios, an at
     * sign (@) can be used to prefix the vendor name. Vendors SHOULD set the tenant ID
     * at the beginning of the key.
     *
     * @see https://www.w3.org/TR/trace-context/#key
     */
    private function validateKey(string $key): bool
    {
        return preg_match(self::VALID_KEY_REGEX, $key) !== 0;
    }
    
    /**
     * The value is an opaque string containing up to 256 printable ASCII [RFC0020]
     * characters (i.e., the range 0x20 to 0x7E) except comma (,) and (=). Note that
     * this also excludes tabs, newlines, carriage returns, etc.
     *
     * @see https://www.w3.org/TR/trace-context/#value
     */
    private function validateValue(string $key): bool
    {
        return (preg_match(self::VALID_VALUE_BASE_REGEX, $key) !== 0)
               && (preg_match(self::INVALID_VALUE_COMMA_EQUAL_REGEX, $key) === 0);
    }
}