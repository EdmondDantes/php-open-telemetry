<?php
declare(strict_types=1);

namespace IfCastle\OpenTelemetry\Protocol;

use IfCastle\Core\Containers\FileTemporary;
use IfCastle\Core\Exceptions\ErrorException;
use IfCastle\Core\Helpers\FileSystem\Exceptions\FileSystemException;
use IfCastle\Core\Helpers\Safe;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceResponse;

class FileTransport         implements TransportInterface, TraceTemporaryStorageInterface
{
    // 1000 MB
    final const MAX_STORAGE_SIZE    = 1024 * 1024 * 1000;
    final const MAX_STORAGE_FILES   = 1000;
    final const MAX_FILE_SIZE       = 1024 * 1024 * 100;
    
    private FileTemporary $fileTemporary;
    private mixed $fileHandle = null;
    private ProtobufSerializer $serializer;
    private int $lastScanFiles      = 0;
    private int $lastScanSize       = 0;
    
    final public const PREFIX       = 0x00005020;
    final public const SUFFIX       = 0x0000FFFF;
    final public const FILE_PREFIX  = 'telemetry';
    final public const PATTERN      = '/^.+(?:\/|\\\\)'.self::FILE_PREFIX.'.+\.tmp$/';
    
    public function __construct()
    {
        $this->fileTemporary        = new FileTemporary(self::FILE_PREFIX);
        $this->fileTemporary->noAutoRemove();
        
        $this->serializer           = ProtobufSerializer::forTransport($this);
    }
    
    public function setConfig(array $config): static
    {
        return $this;
    }
    
    public function contentType(): string
    {
        return TransportInterface::PROTOBUF;
    }
    
    /**
     * @throws FileSystemException
     * @throws ErrorException
     */
    public function send(string $endpoint, string $body): ?string
    {
        // Ignore new data if storage is full
        if($this->lastScanSize > self::MAX_STORAGE_SIZE
           || $this->lastScanFiles > self::MAX_STORAGE_FILES
           || $body === '') {
            return $this->serializer->serialize(new ExportLogsServiceResponse());
        }
        
        if(is_file($this->fileTemporary->getFilePath())) {
            $fileSize               = filesize($this->fileTemporary->getFilePath());
        } else {
            $fileSize               = false;
        }
        
        // If file size is greater than MAX_FILE_SIZE MB, close file handle
        // and start next file...
        if($fileSize !== false && $fileSize > self::MAX_FILE_SIZE) {
            if($this->fileHandle !== null) {
                fclose($this->fileHandle);
                $this->fileHandle   = null;
            }
        }
        
        if($this->fileHandle === null) {
            $this->fileHandle       = Safe::execute(fn() => fopen($this->fileTemporary->getFilePath(), 'a+'));
            
            if($this->fileHandle === false) {
                
                $this->fileHandle   = null;
                
                throw new FileSystemException([
                   'template'       => 'Unable to open file: {file}',
                   'file'           => $this->fileTemporary->getFilePath()
                ]);
            }
            
            // Try to get exclusive lock on file non-blocking
            $lock                   = flock($this->fileHandle, LOCK_EX | LOCK_NB);
            
            if($lock === false) {
                fclose($this->fileHandle);
                $this->fileHandle   = null;
                
                throw new FileSystemException([
                    'template'      => 'Unable to lock file: {file}',
                    'file'          => $this->fileTemporary->getFilePath()
                ]);
            }
        }
        
        // File format:
        // [4bytes]: magic number prefix 0x00005020.
        // [4bytes]: block size.
        // [4bytes]: endpoint length.
        // [nbytes]: endpoint.
        // [nbytes]: Body.
        // [4bytes]: Suffix 0x0000FFFF.
        
        $endpointLength             = strlen($endpoint);
        $blockSize                  = 4 + 4 + 4 + $endpointLength + strlen($body) + 4;
        $data                       = pack('NNN', self::PREFIX, $blockSize, $endpointLength);
        $data                      .= $endpoint;
        $data                      .= $body;
        $data                      .= pack('N', self::SUFFIX);
        
        try {
            Safe::execute(fn() => fwrite($this->fileHandle, $data));
        } catch (\Throwable $throwable) {
            fclose($this->fileHandle);
            $this->fileHandle       = null;
            throw $throwable;
        }
        
        // Emulate response from server
        $serviceResponse            = new ExportLogsServiceResponse();
        
        return $this->serializer->serialize($serviceResponse);
    }
    
    public function finalize(): bool
    {
        if(false === is_file($this->fileTemporary->getFilePath())) {
            return false;
        }
        
        if($this->fileHandle !== null) {
            fclose($this->fileHandle);
        }
        
        $this->fileHandle           = null;
        $this->fileTemporary        = (new FileTemporary(self::FILE_PREFIX))->noAutoRemove();
        
        return true;
    }
    
    public function extractPackets(): iterable
    {
        $tmpDir                     = FileTemporary::getTmpDir();
        $this->lastScanFiles        = 0;
        
        // Get all files in tmp directory with prefix "telemetry" with subdirectories
        foreach(glob($tmpDir . '/**/telemetry*.tmp') as $file) {
            
            if(!is_file($file)) {
                continue;
            }
            
            $this->lastScanFiles++;
            $fileSize               = filesize($file);
            
            if($fileSize === false) {
                continue;
            }
            
            $this->lastScanSize    += $fileSize;
            
            yield from $this->tryExtractPacketsFromFile($file);
        }
    }
    
    protected function tryExtractPacketsFromFile(string $file): iterable
    {
        $fileHandle                 = Safe::execute(fn() => fopen($file, 'r'));
        
        // Try to lock file non-blocking
        $lock                       = flock($fileHandle, LOCK_EX | LOCK_NB);
        
        if($lock === false) {
            fclose($fileHandle);
            return;
        }

        try {
            yield from $this->extractPacketsFromFile($fileHandle, $file);
            unlink($file);
        } finally {
            flock($fileHandle, LOCK_UN);
            fclose($fileHandle);
        }
    }
    
    /**
     * Extract packets from file to array of [string endpoint, string body].
     *
     * @throws ErrorException
     * @throws FileSystemException
     */
    protected function extractPacketsFromFile($fileHandle, string $file): iterable
    {
        $decodeError                = null;

        while(!feof($fileHandle)) {
            
            $prefix                 = Safe::execute(fn() => fread($fileHandle, 4));
            
            if($prefix === false || $prefix === '' || feof($fileHandle)) {
                break;
            }
            
            $prefix                 = unpack('N', $prefix);
            
            if($prefix[1] !== self::PREFIX) {
                $decodeError        = 'Invalid prefix';
                break;
            }
            
            $blockSize              = Safe::execute(fn() => fread($fileHandle, 4));
            
            if($blockSize === false) {
                $decodeError        = 'Invalid block size';
                break;
            }
            
            $blockSize              = unpack('N', $blockSize);
            
            if($blockSize === false) {
                $decodeError        = 'Invalid block size';
                break;
            }
            
            $blockSize              = $blockSize[1] ?? 0;
            
            if($blockSize === 0) {
                $decodeError        = 'Invalid block size';
                break;
            }
            
            $endpointLength         = Safe::execute(fn() => fread($fileHandle, 4));
            
            if($endpointLength === false) {
                $decodeError        = 'Invalid endpoint length';
                break;
            }
            
            $endpointLength         = unpack('N', $endpointLength);
            
            $endpointLength         = $endpointLength[1] ?? 0;
            
            if($endpointLength === 0) {
                $decodeError        = 'Invalid endpoint length';
                break;
            }
            
            $endpoint               = Safe::execute(fn() => fread($fileHandle, $endpointLength));
            
            if($endpoint === false) {
                $decodeError        = 'Invalid endpoint';
                break;
            }
            
            $bodyLength             = $blockSize - 4 - 4 - 4 - $endpointLength - 4;
            
            $body                   = Safe::execute(fn() => fread($fileHandle, $bodyLength));
            
            if($body === false) {
                $decodeError        = 'Invalid body';
                break;
            }
            
            $suffix                 = Safe::execute(fn() => fread($fileHandle, 4));
            
            if($suffix === false) {
                $decodeError        = 'Invalid suffix';
                break;
            }
            
            $suffix                 = unpack('N', $suffix);
            
            if($suffix[1] !== self::SUFFIX) {
                $decodeError        = 'Error suffix mismatch';
                break;
            }
            
            yield [$endpoint, $body];
        }
        
        if($decodeError !== null) {
            throw new FileSystemException([
                'template'          => 'Unable to decode telemetry file {file}: {error}',
                'file'              => $file,
                'error'             => $decodeError
            ]);
        }
    }
    
    public function clearOldPackets(): void
    {
        if($this->lastScanFiles === 0) {
            return;
        }
        
        if($this->lastScanSize < self::MAX_STORAGE_SIZE && $this->lastScanFiles < self::MAX_STORAGE_FILES) {
            return;
        }
        
        // Remove old files
        
        $tmpDir                     = FileTemporary::getTmpDir();
        $prefix                     = 'telemetry';
        
        $iterator                   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tmpDir));
        $files                      = new \RegexIterator($iterator, '/^.+\/' . $prefix . '.+$/i', \RecursiveRegexIterator::GET_MATCH);
        
        foreach($files as $file) {
            $file                   = $file[0];
            
            if(!is_file($file)) {
                continue;
            }
            
            unlink($file);
        }
    }
}