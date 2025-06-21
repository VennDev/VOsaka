<?php

require '../vendor/autoload.php';

use venndev\vosaka\VOsaka;

class OptimizedVOsakaWebCrawler
{
    private array $results = [];
    private array $failed = [];
    private array $stats = [];
    private const MAX_CONCURRENT = 200; // Increased concurrency
    private const BATCH_SIZE = 500; // Larger batches for better efficiency

    public function crawlUrls(array $urls): void
    {
        $startTime = microtime(true);
        $total = count($urls);

        if ($total === 0) {
            $this->updateStats($startTime);
            return;
        }

        // Configure VOsaka for better performance
        VOsaka::setMaxConcurrentTasks(self::MAX_CONCURRENT);
        VOsaka::setEnableMaximumPeriod(false); // Disable period limit for stress tests
        VOsaka::setEnableLogging(false); // Disable logging for performance

        // Process URLs more efficiently
        $this->processUrlsEfficiently($urls, $startTime);
    }

    private function processUrlsEfficiently(array $urls, float $startTime): void
    {
        $batches = array_chunk($urls, self::BATCH_SIZE);

        foreach ($batches as $batchIndex => $batch) {
            $tasks = [];

            // Create all tasks for this batch
            foreach ($batch as $url) {
                $tasks[] = $this->crawlSingleUrlOptimized($url);
            }

            // Spawn all tasks at once for better concurrency
            foreach ($tasks as $task) {
                VOsaka::spawn($task);
            }

            // Run this batch
            VOsaka::run();

            // Reset VOsaka state between batches to prevent memory buildup
            if ($batchIndex % 5 === 4) { // Every 5 batches
                VOsaka::reset();
                gc_collect_cycles();
            }
        }

        $this->updateStats($startTime);
    }

    private function updateStats(float $startTime): void
    {
        $endTime = microtime(true);
        $this->stats = [
            'duration' => round($endTime - $startTime, 2),
            'successful' => count($this->results),
            'failed' => count($this->failed),
            'memory_peak' => number_format(memory_get_peak_usage() / 1024 / 1024, 2),
            'memory_current' => number_format(memory_get_usage() / 1024 / 1024, 2),
            'processed' => count($this->results) + count($this->failed)
        ];
    }

    private function crawlSingleUrlOptimized(string $url): Generator
    {
        try {
            // Optimized sleep with reduced precision for better performance
            $sleepTime = mt_rand(1, 3) / 10;
            yield from VOsaka::sleep($sleepTime);

            $success = mt_rand(1, 10) > 2; // Use mt_rand for better performance

            if ($success) {
                // Pre-calculate content for better performance
                $pageNum = $this->extractPageNumber($url);
                $content = $this->generateContent($pageNum);

                $this->results[] = [
                    'url' => $url,
                    'title' => "Page {$pageNum}",
                    'size' => strlen($content),
                    'memory' => memory_get_usage()
                ];
            } else {
                $this->failed[] = $url;
            }
        } catch (Exception $e) {
            $this->failed[] = $url;
        }
    }

    private function extractPageNumber(string $url): string
    {
        // Extract page number more efficiently
        if (preg_match('/page-(\d+)/', $url, $matches)) {
            return $matches[1];
        }
        return basename($url);
    }

    private function generateContent(string $pageNum): string
    {
        // Generate content more efficiently
        return "<html><head><title>Page {$pageNum}</title></head><body>Content</body></html>";
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    public function reset(): void
    {
        $this->results = [];
        $this->failed = [];
        $this->stats = [];
        VOsaka::reset();
        gc_collect_cycles();
    }
}

// Updated test functions for better performance measurement
function benchmarkOptimizedCrawlers(): array
{
    $urls = [];
    for ($i = 1; $i <= 100; $i++) {
        $urls[] = "https://example.com/page-{$i}";
    }

    echo "Testing Optimized VOsaka...\n";
    $vosaka = new OptimizedVOsakaWebCrawler();
    $vosaka->crawlUrls($urls);
    $vosakaStats = $vosaka->getStats();

    // Clean reset
    $vosaka->reset();
    unset($vosaka);

    return ['vosaka' => $vosakaStats];
}

function stressTestOptimizedVOsaka(): array
{
    $taskCounts = [100, 500, 1000, 2000, 5000, 10000]; // Added 10K test
    $results = [];

    foreach ($taskCounts as $count) {
        echo "Stress testing optimized VOsaka with {$count} tasks...\n";

        $urls = [];
        for ($i = 1; $i <= $count; $i++) {
            $urls[] = "https://stress-test.com/page-{$i}";
        }

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        try {
            $vosaka = new OptimizedVOsakaWebCrawler();
            $vosaka->crawlUrls($urls);
            $stats = $vosaka->getStats();

            $memoryUsed = (memory_get_peak_usage() - $startMemory) / 1024 / 1024;
            $tasksPerSecond = $stats['duration'] > 0 ? round($count / $stats['duration'], 2) : 'N/A';

            $results[] = [
                'tasks' => $count,
                'duration' => $stats['duration'],
                'tasks_per_sec' => $tasksPerSecond,
                'memory_used' => round($memoryUsed, 2),
                'memory_per_task' => round($memoryUsed / $count * 1024, 2),
                'processed' => $stats['processed'],
                'successful' => $stats['successful'],
                'failed' => $stats['failed'],
                'memory_peak' => $stats['memory_peak'],
                'memory_current' => $stats['memory_current']
            ];

            $vosaka->reset();
            unset($vosaka);
        } catch (Exception $e) {
            $results[] = [
                'tasks' => $count,
                'error' => $e->getMessage(),
                'processed' => 0
            ];
        }

        // Force cleanup between tests
        gc_collect_cycles();

        // Give system a moment to recover
        usleep(100000); // 100ms
    }

    return $results;
}

function memoryLeakTestOptimizedVOsaka(): array
{
    $results = [];
    $baseMemory = memory_get_usage();

    for ($batch = 1; $batch <= 10; $batch++) { // Increased to 10 batches
        $urls = [];
        for ($i = 1; $i <= 100; $i++) { // Increased to 100 URLs per batch
            $urls[] = "https://test.com/batch-{$batch}-page-{$i}";
        }

        $startMemory = memory_get_usage();

        $vosaka = new OptimizedVOsakaWebCrawler();
        $vosaka->crawlUrls($urls);

        $endMemory = memory_get_usage();
        $memoryDiff = ($endMemory - $startMemory) / 1024 / 1024;
        $totalLeak = ($endMemory - $baseMemory) / 1024 / 1024;

        $results[] = [
            'batch' => $batch,
            'urls' => count($urls),
            'memory_diff' => round($memoryDiff, 2),
            'leak' => round($totalLeak, 2),
            'memory_start' => round($startMemory / 1024 / 1024, 2),
            'memory_end' => round($endMemory / 1024 / 1024, 2)
        ];

        $vosaka->reset();
        unset($vosaka);
        gc_collect_cycles();
    }

    return $results;
}

// Run optimized tests
echo "Starting optimized VOsaka benchmark tests...\n\n";

$optimizedBenchmark = benchmarkOptimizedCrawlers();
echo "\nOptimized benchmark completed. Running memory leak test...\n";

$memoryLeakResults = memoryLeakTestOptimizedVOsaka();
echo "\nMemory leak test completed. Running stress test...\n";

$stressTestResults = stressTestOptimizedVOsaka();

echo "\n=== OPTIMIZED VOSAKA RESULTS ===\n";

// Benchmark Results
echo "\nOptimized Benchmark Test (100 URLs):\n";
echo "-------------------------------------\n";
echo "Duration: " . ($optimizedBenchmark['vosaka']['duration'] ?? 'N/A') . "s\n";
echo "Successful: " . ($optimizedBenchmark['vosaka']['successful'] ?? 0) . "\n";
echo "Failed: " . ($optimizedBenchmark['vosaka']['failed'] ?? 0);
