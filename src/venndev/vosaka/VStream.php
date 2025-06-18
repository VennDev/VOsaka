<?php

declare(strict_types=1);

namespace venndev\vosaka;

use Generator;

final class VStream
{
    private const CHUNK_SIZE = 8192;
    private const READ_TIMEOUT = 30;

    public static function read(string $pathOrUrl, array|null $options = null): Generator
    {
        if (self::isUrl($pathOrUrl)) {
            yield from self::readFromUrl($pathOrUrl, $options);
        } else {
            yield from self::readFromFile($pathOrUrl);
        }
    }

    public static function write(string $path, string $data): Generator
    {
        if (self::isUrl($path)) {
            throw new \InvalidArgumentException("Writing to URLs is not supported: $path");
        }

        yield from self::writeToFile($path, $data);
    }

    public static function writeStream(string $path, Generator $dataStream): Generator
    {
        if (self::isUrl($path)) {
            throw new \InvalidArgumentException("Writing to URLs is not supported: $path");
        }

        $stream = @fopen($path, 'wb');
        if (!$stream) {
            throw new \RuntimeException("Failed to open file for writing: $path");
        }

        $totalBytesWritten = 0;

        try {
            foreach ($dataStream as $chunk) {
                $bytesWritten = fwrite($stream, $chunk);
                if ($bytesWritten === false) {
                    throw new \RuntimeException("Failed to write to file: $path");
                }
                $totalBytesWritten += $bytesWritten;
                yield $bytesWritten;
            }
        } finally {
            fclose($stream);
        }

        return $totalBytesWritten;
    }

    public static function copy(string $source, string $destination): Generator
    {
        $sourceStream = self::read($source);
        $destinationStream = self::writeStream($destination, $sourceStream);

        $totalBytes = 0;
        foreach ($destinationStream as $bytesWritten) {
            $totalBytes += $bytesWritten;
            yield $bytesWritten;
        }

        return $totalBytes;
    }

    private static function isUrl(string $path): bool
    {
        return filter_var($path, FILTER_VALIDATE_URL) !== false ||
            preg_match('/^https?:\/\//', $path) === 1;
    }

    private static function readFromFile(string $path): Generator
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("File does not exist: $path");
        }

        if (!is_readable($path)) {
            throw new \InvalidArgumentException("File is not readable: $path");
        }

        $stream = @fopen($path, 'rb');
        if (!$stream) {
            throw new \RuntimeException("Failed to open file: $path");
        }

        try {
            while (!feof($stream)) {
                $chunk = fread($stream, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new \RuntimeException("Failed to read file: $path");
                }
                if ($chunk !== '') {
                    yield $chunk;
                }
            }
        } finally {
            fclose($stream);
        }
    }

    private static function readFromUrl(string $url, array|null $options = null): Generator
    {
        $context = stream_context_create($options ?? [
            'http' => [
                'timeout' => self::READ_TIMEOUT,
                'user_agent' => 'VStream/1.0',
                'follow_location' => true,
                'max_redirects' => 5,
            ],
            'https' => [
                'timeout' => self::READ_TIMEOUT,
                'user_agent' => 'VStream/1.0',
                'follow_location' => true,
                'max_redirects' => 5,
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);

        $stream = @fopen($url, 'rb', false, $context);
        if (!$stream) {
            throw new \RuntimeException("Failed to open URL: $url");
        }

        try {
            stream_set_timeout($stream, self::READ_TIMEOUT);

            while (!feof($stream)) {
                $chunk = fread($stream, self::CHUNK_SIZE);
                if ($chunk === false) {
                    $meta = stream_get_meta_data($stream);
                    if ($meta['timed_out']) {
                        throw new \RuntimeException("Timeout while reading URL: $url");
                    }
                    throw new \RuntimeException("Failed to read URL: $url");
                }
                if ($chunk !== '') {
                    yield $chunk;
                }
            }
        } finally {
            fclose($stream);
        }
    }

    private static function writeToFile(string $path, string $data): Generator
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                throw new \RuntimeException("Failed to create directory: $directory");
            }
        }

        $stream = @fopen($path, 'wb');
        if (!$stream) {
            throw new \RuntimeException("Failed to open file for writing: $path");
        }

        try {
            $bytesWritten = fwrite($stream, $data);
            if ($bytesWritten === false) {
                throw new \RuntimeException("Failed to write to file: $path");
            }
            yield $bytesWritten;
        } finally {
            fclose($stream);
        }
    }

    public static function getSize(string $pathOrUrl): int
    {
        if (self::isUrl($pathOrUrl)) {
            return self::getUrlSize($pathOrUrl);
        } else {
            if (!file_exists($pathOrUrl)) {
                throw new \InvalidArgumentException("File does not exist: $pathOrUrl");
            }
            $size = filesize($pathOrUrl);
            if ($size === false) {
                throw new \RuntimeException("Failed to get file size: $pathOrUrl");
            }
            return $size;
        }
    }

    private static function getUrlSize(string $url): int
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'HEAD',
                'timeout' => self::READ_TIMEOUT,
                'user_agent' => 'VStream/1.0',
            ]
        ]);

        $headers = @get_headers($url, true, $context);
        if ($headers === false) {
            throw new \RuntimeException("Failed to get headers for URL: $url");
        }

        $contentLength = null;
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, 'Content-Length') === 0) {
                $contentLength = is_array($value) ? end($value) : $value;
                break;
            }
        }

        if ($contentLength === null) {
            throw new \RuntimeException("Content-Length header not found for URL: $url");
        }

        return (int) $contentLength;
    }

    public static function exists(string $pathOrUrl): bool
    {
        if (self::isUrl($pathOrUrl)) {
            return self::urlExists($pathOrUrl);
        } else {
            return file_exists($pathOrUrl);
        }
    }

    private static function urlExists(string $url): bool
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'HEAD',
                'timeout' => self::READ_TIMEOUT,
                'user_agent' => 'VStream/1.0',
            ]
        ]);

        $headers = @get_headers($url, false, $context);
        if ($headers === false) {
            return false;
        }

        $statusLine = $headers[0] ?? '';
        return strpos($statusLine, '200') !== false || strpos($statusLine, '301') !== false || strpos($statusLine, '302') !== false;
    }
}
