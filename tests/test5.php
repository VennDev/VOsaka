<?php

require '../vendor/autoload.php';

use venndev\vosaka\VTraceHelper;

// Initialize tracing with configuration
VTraceHelper::init([
    'max_traces' => 1000,
    'auto_flush' => true,
    'flush_threshold' => 50,
    'include_stack_trace' => true,
    'min_duration_ms' => 1,
    'output_format' => 'json',
    'output_file' => 'vosaka_traces.log',
    'memory_limit_mb' => 100
]);

// Example 1: Basic traced operations
function basicTracedExample(): Generator
{
    // This will be automatically traced
    yield from VTraceHelper::traceSleep(1, ['example' => 'basic', 'priority' => 'high']);
    
    // Add custom log entries
    VTraceHelper::log('Custom operation started', ['operation' => 'data_processing']);
    
    // Add custom tags
    VTraceHelper::tag(['user_id' => 12345, 'feature' => 'background_task']);
    
    yield from VTraceHelper::traceSleep(0.5, ['step' => 'processing']);
    
    VTraceHelper::log('Custom operation completed');
    
    return 'Basic example completed';
}

// Example 2: Traced generator with custom logic
function dataProcessingTask(int $iterations): Generator
{
    for ($i = 0; $i < $iterations; $i++) {
        VTraceHelper::log("Processing iteration {$i}", ['iteration' => $i]);
        
        // Simulate some work
        yield from VTraceHelper::traceSleep(0.1, ['iteration' => $i, 'type' => 'processing']);
        
        // Add progress tags
        VTraceHelper::tag(['progress' => round(($i + 1) / $iterations * 100, 2)]);
    }
    
    return "Processed {$iterations} iterations";
}

// Example 3: Error handling with tracing
function errorProneTask(): Generator
{
    try {
        VTraceHelper::log('Starting error-prone task');
        
        yield from VTraceHelper::traceSleep(0.5, ['stage' => 'preparation']);
        
        // Simulate random error
        if (rand(1, 3) === 1) {
            throw new Exception('Simulated error occurred');
        }
        
        yield from VTraceHelper::traceSleep(0.3, ['stage' => 'execution']);
        
        VTraceHelper::log('Task completed successfully');
        return 'Success';
        
    } catch (Exception $e) {
        VTraceHelper::log('Error occurred: ' . $e->getMessage(), ['level' => 'error']);
        throw $e;
    }
}

// Example 4: Complex traced workflow
function complexWorkflow(): Generator
{
    VTraceHelper::log('Starting complex workflow');
    
    // Step 1: Data preparation
    yield from VTraceHelper::trace(
        dataProcessingTask(5),
        'data_preparation',
        ['workflow_step' => 1, 'importance' => 'critical']
    );
    
    // Step 2: Parallel processing with join
    $task1 = VTraceHelper::trace(
        dataProcessingTask(3),
        'parallel_task_1',
        ['workflow_step' => 2, 'parallel_group' => 'A']
    );
    
    $task2 = VTraceHelper::trace(
        dataProcessingTask(3),
        'parallel_task_2',
        ['workflow_step' => 2, 'parallel_group' => 'A']
    );
    
    VTraceHelper::traceJoin(
        [$task1, $task2],
        ['parallel_task_1', 'parallel_task_2'],
        ['workflow_step' => 2, 'operation' => 'parallel_join']
    );
    
    // Step 3: Final processing
    yield from VTraceHelper::trace(
        dataProcessingTask(2),
        'final_processing',
        ['workflow_step' => 3, 'importance' => 'high']
    );
    
    VTraceHelper::log('Complex workflow completed');
    return 'Workflow completed successfully';
}

// Example 5: Using select with tracing
function selectExample(): Generator
{
    yield VTraceHelper::log('Starting select example');
    
    $fastTask = function(): Generator {
        yield from VTraceHelper::traceSleep(0.5, ['task_type' => 'fast']);
        return 'Fast task completed';
    };
    
    $slowTask = function(): Generator {
        yield from VTraceHelper::traceSleep(2, ['task_type' => 'slow']);
        return 'Slow task completed';
    };
    
    VTraceHelper::traceSelect(
        [$fastTask(), $slowTask()],
        ['fast_task', 'slow_task'],
        ['operation' => 'race_condition']
    );
    
    VTraceHelper::log('Select example completed');
}

// Example 6: Defer with tracing
function deferExample(): Generator
{
    VTraceHelper::log('Starting defer example');
    
    // Register deferred cleanup
    VTraceHelper::traceDefer(
        function() {
            VTraceHelper::log('Cleanup executed', ['level' => 'info']);
            // Cleanup code here
        },
        'cleanup_operation',
        ['priority' => 'high']
    );
    
    yield from VTraceHelper::traceSleep(1, ['main_operation' => true]);
    
    VTraceHelper::log('Main operation completed');
    return 'Defer example completed';
}

// Example 7: Spawn with tracing
function spawnExample(): Generator
{
    VTraceHelper::log('Starting spawn example');
    
    // Spawn background tasks
    for ($i = 0; $i < 3; $i++) {
        VTraceHelper::traceSpawn(
            backgroundTask($i),
            "background_task_{$i}",
            ['task_id' => $i, 'priority' => 'low']
        );
    }
    
    // Main task
    yield from VTraceHelper::traceSleep(1, ['main_task' => true]);
    
    VTraceHelper::log('Spawn example completed');
    return 'Spawned tasks running in background';
}

function backgroundTask(int $taskId): Generator
{
    $fn = function() use ($taskId): Generator {
        VTraceHelper::log("Background task {$taskId} started");
        yield from VTraceHelper::traceSleep(rand(1, 3), ['task_id' => $taskId]);
        VTraceHelper::log("Background task {$taskId} completed");
        return "Task {$taskId} result";
    };
    yield from VTraceHelper::trace(
        $fn(),
        "background_task_{$taskId}",
        ['background' => true, 'task_id' => $taskId]
    );
}

// Example 8: Performance monitoring
function performanceMonitoringExample(): Generator
{
    VTraceHelper::log('Starting performance monitoring example');
    
    $startTime = microtime(true);
    $startMemory = memory_get_usage(true);
    
    // Simulate CPU intensive task
    for ($i = 0; $i < 10; $i++) {
        yield from VTraceHelper::trace(
            cpuIntensiveTask($i),
            "cpu_task_{$i}",
            ['cpu_intensive' => true, 'iteration' => $i]
        );
        
        // Monitor memory usage
        $currentMemory = memory_get_usage(true);
        $memoryDelta = $currentMemory - $startMemory;
        
        VTraceHelper::tag([
            'memory_usage_bytes' => $currentMemory,
            'memory_delta_bytes' => $memoryDelta,
            'iteration' => $i
        ]);
        
        if ($memoryDelta > 10 * 1024 * 1024) { // 10MB threshold
            VTraceHelper::log('High memory usage detected', [
                'level' => 'warning',
                'memory_mb' => round($memoryDelta / 1024 / 1024, 2)
            ]);
        }
    }
    
    $totalTime = microtime(true) - $startTime;
    VTraceHelper::log('Performance monitoring completed', [
        'total_time_seconds' => round($totalTime, 3),
        'final_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
    ]);
    
    return 'Performance monitoring completed';
}

function cpuIntensiveTask(int $iteration): Generator
{
    // Simulate CPU work
    $result = 0;
    for ($i = 0; $i < 100000; $i++) {
        $result += sqrt($i);
    }
    
    yield from VTraceHelper::traceSleep(0.1, ['computation_result' => $result]);
    
    return $result;
}

// Main execution function
function main(): Generator
{
    VTraceHelper::log('Application started', [
        'php_version' => PHP_VERSION,
        'memory_limit' => ini_get('memory_limit')
    ]);
    
    try {
        // Run examples
        echo "Running basic traced example...\n";
        $result1 = yield from VTraceHelper::traceAwait(basicTracedExample(), 'basic_example');
        echo "Result: {$result1}\n\n";
        
        echo "Running complex workflow...\n";
        $result2 = yield from VTraceHelper::traceAwait(complexWorkflow(), 'complex_workflow');
        echo "Result: {$result2}\n\n";
        
        echo "Running select example...\n";
        yield from selectExample();
        echo "Select example completed\n\n";
        
        echo "Running defer example...\n";
        $result3 = yield from VTraceHelper::traceAwait(deferExample(), 'defer_example');
        echo "Result: {$result3}\n\n";
        
        echo "Running spawn example...\n";
        $result4 = yield from VTraceHelper::traceAwait(spawnExample(), 'spawn_example');
        echo "Result: {$result4}\n\n";
        
        echo "Running performance monitoring...\n";
        $result5 = yield from VTraceHelper::traceAwait(performanceMonitoringExample(), 'performance_monitoring');
        echo "Result: {$result5}\n\n";
        
        // Error handling example
        echo "Running error-prone task...\n";
        try {
            $result6 = yield from VTraceHelper::traceAwait(errorProneTask(), 'error_prone_task');
            echo "Result: {$result6}\n\n";
        } catch (Exception $e) {
            echo "Caught error: {$e->getMessage()}\n\n";
        }
        
    } catch (Exception $e) {
        VTraceHelper::log('Application error: ' . $e->getMessage(), ['level' => 'error']);
        throw $e;
    }
    
    // Print statistics
    echo "=== Trace Statistics ===\n";
    $stats = VTraceHelper::getStats();
    foreach ($stats as $key => $value) {
        echo "{$key}: {$value}\n";
    }
    
    // Export traces
    echo "\n=== Exporting Traces ===\n";
    $jsonExport = VTraceHelper::export('json');
    file_put_contents('traces_export.json', $jsonExport);
    echo "JSON export saved to traces_export.json\n";
    
    $textExport = VTraceHelper::export('text');
    file_put_contents('traces_export.txt', $textExport);
    echo "Text export saved to traces_export.txt\n";
    
    VTraceHelper::log('Application completed successfully');
    
    return 'All examples completed';
}

// Run the main function
VTraceHelper::traceSpawn(main(), 'main_application', ['app_version' => '1.0.0']);

// Start the event loop with tracing
VTraceHelper::traceRun(['execution_mode' => 'traced']);

// Final flush and cleanup
echo "\n=== Final Trace Flush ===\n";
VTraceHelper::flush();
echo "Traces flushed to log file\n";

// Display final statistics
echo "\n=== Final Statistics ===\n";
$finalStats = VTraceHelper::getStats();
foreach ($finalStats as $key => $value) {
    echo "{$key}: {$value}\n";
}

VTraceHelper::clear();
echo "\nTrace system cleaned up\n";