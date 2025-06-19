<?php

declare(strict_types=1);

namespace venndev\vosaka;

use Generator;
use Throwable;

/**
 * Tracer class for VOsaka - provides comprehensive tracing capabilities
 * for asynchronous operations, performance monitoring, and debugging
 */
final class VTracer
{
    private static ?VTracer $instance = null;
    private bool $enabled = false;
    private array $traces = [];
    private array $activeSpans = [];
    private array $config = [
        'max_traces' => 1000,
        'auto_flush' => true,
        'flush_threshold' => 100,
        'include_stack_trace' => true,
        'min_duration_ms' => 0,
        'output_format' => 'json', // json, text, structured
        'output_file' => null,
        'memory_limit_mb' => 50
    ];
    private int $spanIdCounter = 0;
    private array $contextStack = [];

    private function __construct()
    {
        $this->traces = [];
        $this->activeSpans = [];
        $this->contextStack = [];
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Enable tracing with optional configuration
     */
    public function enable(array $config = []): self
    {
        $this->enabled = true;
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    /**
     * Disable tracing
     */
    public function disable(): self
    {
        $this->enabled = false;
        return $this;
    }

    /**
     * Check if tracing is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Start a new trace span
     */
    public function startSpan(string $operation, array $tags = [], ?string $parentSpanId = null): string
    {
        if (!$this->enabled) {
            return '';
        }

        $spanId = $this->generateSpanId();
        $timestamp = microtime(true);
        
        $span = [
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId ?? $this->getCurrentSpanId(),
            'operation' => $operation,
            'start_time' => $timestamp,
            'end_time' => null,
            'duration_ms' => null,
            'tags' => $tags,
            'logs' => [],
            'status' => 'active',
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];

        $this->activeSpans[$spanId] = $span;
        $this->pushContext($spanId);
        
        return $spanId;
    }

    /**
     * Finish a trace span
     */
    public function finishSpan(string $spanId, array $finalTags = []): void
    {
        if (!$this->enabled || !isset($this->activeSpans[$spanId])) {
            return;
        }

        $span = &$this->activeSpans[$spanId];
        $endTime = microtime(true);
        $duration = ($endTime - $span['start_time']) * 1000; // Convert to milliseconds

        $span['end_time'] = $endTime;
        $span['duration_ms'] = round($duration, 3);
        $span['status'] = 'completed';
        $span['tags'] = array_merge($span['tags'], $finalTags);
        $span['final_memory_usage'] = memory_get_usage(true);
        $span['memory_delta'] = $span['final_memory_usage'] - $span['memory_usage'];

        // Only keep traces that meet minimum duration threshold
        if ($duration >= $this->config['min_duration_ms']) {
            $this->traces[] = $span;
        }

        unset($this->activeSpans[$spanId]);
        $this->popContext($spanId);

        // Auto-flush if threshold reached
        if ($this->config['auto_flush'] && count($this->traces) >= $this->config['flush_threshold']) {
            $this->flush();
        }

        // Memory management
        $this->checkMemoryLimit();
    }

    /**
     * Add a log entry to current or specified span
     */
    public function log(string $message, array $fields = [], ?string $spanId = null): void
    {
        if (!$this->enabled) {
            return;
        }

        $targetSpanId = $spanId ?? $this->getCurrentSpanId();
        
        if ($targetSpanId && isset($this->activeSpans[$targetSpanId])) {
            $this->activeSpans[$targetSpanId]['logs'][] = [
                'timestamp' => microtime(true),
                'message' => $message,
                'fields' => $fields,
                'level' => $fields['level'] ?? 'info'
            ];
        }
    }

    /**
     * Add tags to current or specified span
     */
    public function tag(array $tags, ?string $spanId = null): void
    {
        if (!$this->enabled) {
            return;
        }

        $targetSpanId = $spanId ?? $this->getCurrentSpanId();
        
        if ($targetSpanId && isset($this->activeSpans[$targetSpanId])) {
            $this->activeSpans[$targetSpanId]['tags'] = array_merge(
                $this->activeSpans[$targetSpanId]['tags'], 
                $tags
            );
        }
    }

    /**
     * Mark span as failed with error information
     */
    public function recordError(Throwable $error, ?string $spanId = null): void
    {
        if (!$this->enabled) {
            return;
        }

        $targetSpanId = $spanId ?? $this->getCurrentSpanId();
        
        if ($targetSpanId && isset($this->activeSpans[$targetSpanId])) {
            $this->activeSpans[$targetSpanId]['status'] = 'error';
            $this->activeSpans[$targetSpanId]['error'] = [
                'type' => get_class($error),
                'message' => $error->getMessage(),
                'code' => $error->getCode(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => $this->config['include_stack_trace'] ? $error->getTraceAsString() : null
            ];
        }
    }

    /**
     * Trace a Generator function automatically
     */
    public function traceGenerator(Generator $generator, string $operation, array $tags = []): Generator
    {
        if (!$this->enabled) {
            yield from $generator;
            return;
        }

        $spanId = $this->startSpan($operation, array_merge(['type' => 'generator'], $tags));
        
        try {
            $yieldCount = 0;
            while ($generator->valid()) {
                $value = $generator->current();
                $this->log("Generator yielded", ['yield_count' => ++$yieldCount, 'value_type' => gettype($value)]);
                
                yield $value;
                $generator->next();
            }
            
            $result = $generator->getReturn();
            $this->tag(['yield_count' => $yieldCount, 'completed' => true], $spanId);
            $this->finishSpan($spanId);
            
            return $result;
        } catch (Throwable $e) {
            $this->recordError($e, $spanId);
            $this->finishSpan($spanId, ['error' => true]);
            throw $e;
        }
    }

    /**
     * Trace a callable function
     */
    public function traceCallable(callable $callable, string $operation, array $args = [], array $tags = [])
    {
        if (!$this->enabled) {
            return $callable(...$args);
        }

        $spanId = $this->startSpan($operation, array_merge(['type' => 'callable'], $tags));
        
        try {
            $result = $callable(...$args);
            $this->tag(['completed' => true], $spanId);
            $this->finishSpan($spanId);
            return $result;
        } catch (Throwable $e) {
            $this->recordError($e, $spanId);
            $this->finishSpan($spanId, ['error' => true]);
            throw $e;
        }
    }

    /**
     * Get all completed traces
     */
    public function getTraces(): array
    {
        return $this->traces;
    }

    /**
     * Get active spans
     */
    public function getActiveSpans(): array
    {
        return $this->activeSpans;
    }

    /**
     * Get trace statistics
     */
    public function getStats(): array
    {
        $totalTraces = count($this->traces);
        $activeSpans = count($this->activeSpans);
        $errorCount = count(array_filter($this->traces, fn($trace) => $trace['status'] === 'error'));
        
        $durations = array_column($this->traces, 'duration_ms');
        $memoryUsages = array_column($this->traces, 'memory_delta');
        
        return [
            'total_traces' => $totalTraces,
            'active_spans' => $activeSpans,
            'error_count' => $errorCount,
            'success_rate' => $totalTraces > 0 ? round((($totalTraces - $errorCount) / $totalTraces) * 100, 2) : 0,
            'avg_duration_ms' => $durations ? round(array_sum($durations) / count($durations), 3) : 0,
            'max_duration_ms' => $durations ? max($durations) : 0,
            'min_duration_ms' => $durations ? min($durations) : 0,
            'total_memory_delta' => $memoryUsages ? array_sum($memoryUsages) : 0,
            'avg_memory_delta' => $memoryUsages ? round(array_sum($memoryUsages) / count($memoryUsages), 2) : 0
        ];
    }

    /**
     * Export traces in various formats
     */
    public function export(string $format = null): string
    {
        $format = $format ?? $this->config['output_format'];
        
        switch ($format) {
            case 'json':
                return $this->exportJson();
            case 'text':
                return $this->exportText();
            case 'structured':
                return $this->exportStructured();
            default:
                return $this->exportJson();
        }
    }

    /**
     * Flush traces to output file or return as string
     */
    public function flush(): string
    {
        $output = $this->export();
        
        if ($this->config['output_file']) {
            file_put_contents($this->config['output_file'], $output . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
        
        // Clear traces after flush to free memory
        $this->traces = [];
        
        return $output;
    }

    /**
     * Clear all traces and active spans
     */
    public function clear(): void
    {
        $this->traces = [];
        $this->activeSpans = [];
        $this->contextStack = [];
    }

    // Private helper methods

    private function generateSpanId(): string
    {
        return 'span_' . (++$this->spanIdCounter) . '_' . uniqid();
    }

    private function getCurrentSpanId(): ?string
    {
        return end($this->contextStack) ?: null;
    }

    private function pushContext(string $spanId): void
    {
        $this->contextStack[] = $spanId;
    }

    private function popContext(string $spanId): void
    {
        $key = array_search($spanId, $this->contextStack);
        if ($key !== false) {
            array_splice($this->contextStack, $key, 1);
        }
    }

    private function checkMemoryLimit(): void
    {
        $memoryMB = memory_get_usage(true) / 1024 / 1024;
        if ($memoryMB > $this->config['memory_limit_mb']) {
            // Keep only the most recent traces
            $keepCount = (int)($this->config['max_traces'] * 0.7);
            $this->traces = array_slice($this->traces, -$keepCount);
        }
    }

    private function exportJson(): string
    {
        return json_encode([
            'traces' => $this->traces,
            'stats' => $this->getStats(),
            'exported_at' => date('c'),
            'config' => $this->config
        ], JSON_PRETTY_PRINT);
    }

    private function exportText(): string
    {
        $output = "VOsaka Trace Report - " . date('Y-m-d H:i:s') . "\n";
        $output .= str_repeat("=", 50) . "\n\n";
        
        $stats = $this->getStats();
        $output .= "Statistics:\n";
        $output .= "  Total Traces: {$stats['total_traces']}\n";
        $output .= "  Active Spans: {$stats['active_spans']}\n";
        $output .= "  Error Count: {$stats['error_count']}\n";
        $output .= "  Success Rate: {$stats['success_rate']}%\n";
        $output .= "  Avg Duration: {$stats['avg_duration_ms']}ms\n\n";
        
        foreach ($this->traces as $trace) {
            $output .= "Span: {$trace['operation']} ({$trace['span_id']})\n";
            $output .= "  Duration: {$trace['duration_ms']}ms\n";
            $output .= "  Status: {$trace['status']}\n";
            $output .= "  Memory Delta: " . number_format($trace['memory_delta']) . " bytes\n";
            
            if (!empty($trace['tags'])) {
                $output .= "  Tags: " . json_encode($trace['tags']) . "\n";
            }
            
            if (!empty($trace['logs'])) {
                $output .= "  Logs:\n";
                foreach ($trace['logs'] as $log) {
                    $output .= "    [{$log['level']}] {$log['message']}\n";
                }
            }
            
            if (isset($trace['error'])) {
                $output .= "  Error: {$trace['error']['type']} - {$trace['error']['message']}\n";
            }
            
            $output .= "\n";
        }
        
        return $output;
    }

    private function exportStructured(): string
    {
        $structured = [];
        
        foreach ($this->traces as $trace) {
            $structured[] = [
                'timestamp' => date('c', $trace['start_time']),
                'operation' => $trace['operation'],
                'duration_ms' => $trace['duration_ms'],
                'status' => $trace['status'],
                'span_id' => $trace['span_id'],
                'parent_span_id' => $trace['parent_span_id'],
                'tags' => $trace['tags'],
                'memory_delta' => $trace['memory_delta'],
                'has_error' => isset($trace['error']),
                'log_count' => count($trace['logs'])
            ];
        }
        
        return json_encode($structured, JSON_PRETTY_PRINT);
    }
}