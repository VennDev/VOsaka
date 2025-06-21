<?php

require '../vendor/autoload.php';

use venndev\vosaka\VOsaka;

class VOsakaWebCrawler
{
    private array $results = [];
    private array $failed = [];

    public function crawlUrls(array $urls): void
    {
        $startTime = microtime(true);
        $tasks = [];

        foreach ($urls as $url) {
            $tasks[] = $this->crawlSingleUrl($url);
        }

        foreach ($tasks as $task) {
            VOsaka::spawn($task);
        }

        VOsaka::run();

        $endTime = microtime(true);
        $this->printStats($endTime - $startTime);
    }

    private function crawlSingleUrl(string $url): Generator
    {
        try {
            // Simulate HTTP request delay
            yield from VOsaka::sleep(rand(1, 3));

            // Simulate fetching content
            $content = $this->fetchContent($url);

            if ($content) {
                $this->results[] = [
                    'url' => $url,
                    'title' => $this->extractTitle($content),
                    'size' => strlen($content),
                    'memory' => memory_get_usage()
                ];
                yield var_dump("Crawled: {$url}");
            } else {
                $this->failed[] = $url;
                yield var_dump("Failed: {$url}");
            }

        } catch (Exception $e) {
            $this->failed[] = $url;
            yield var_dump("Error {$url}: " . $e->getMessage());
        }
    }

    private function fetchContent(string $url): string|false
    {
        // Simulate HTTP request
        $success = rand(1, 10) > 2; // 80% success rate

        if (!$success) {
            return false;
        }

        return "<html><head><title>Page " . basename($url) . "</title></head><body>Content for {$url}</body></html>";
    }

    private function extractTitle(string $content): string
    {
        preg_match('/<title>(.*?)<\/title>/i', $content, $matches);
        return $matches[1] ?? 'No title';
    }

    private function printStats(float $duration): void
    {
        echo "\n=== VOsaka Results ===\n";
        echo "Duration: " . round($duration, 2) . "s\n";
        echo "Successful: " . count($this->results) . "\n";
        echo "Failed: " . count($this->failed) . "\n";
        echo "Memory Peak: " . number_format(memory_get_peak_usage() / 1024 / 1024, 2) . " MB\n";
        echo "Memory Current: " . number_format(memory_get_usage() / 1024 / 1024, 2) . " MB\n";
    }
}

function benchmarkCrawlers(): void
{
    // Generate test URLs
    $urls = [];
    for ($i = 1; $i <= 100; $i++) {
        $urls[] = "https://example.com/page-{$i}";
    }

    echo "Testing with " . count($urls) . " URLs...\n\n";

    // Test VOsaka
    echo "=== Testing VOsaka ===\n";
    $vosaka = new VOsakaWebCrawler();
    $vosaka->crawlUrls($urls);

    // Reset memory
    gc_collect_cycles();
}

// ==================== MEMORY LEAK TEST ====================

function memoryLeakTest(): void
{
    echo "\n=== Memory Leak Test ===\n";

    for ($batch = 1; $batch <= 10; $batch++) {
        echo "Batch {$batch}: ";

        $urls = [];
        for ($i = 1; $i <= 50; $i++) {
            $urls[] = "https://test.com/batch-{$batch}-page-{$i}";
        }

        $startMemory = memory_get_usage();

        // Test VOsaka
        $vosaka = new VOsakaWebCrawler();
        $vosaka->crawlUrls($urls);

        $endMemory = memory_get_usage();
        $memoryDiff = ($endMemory - $startMemory) / 1024 / 1024;

        echo "Memory diff: " . round($memoryDiff, 2) . " MB\n";

        // Force garbage collection
        unset($vosaka);
        gc_collect_cycles();

        // Check if memory is released
        $afterGC = memory_get_usage();
        $leaked = ($afterGC - $startMemory) / 1024 / 1024;

        if ($leaked > 1) { // More than 1MB leaked
            echo "Potential memory leak: " . round($leaked, 2) . " MB\n";
        }
    }
}

// ==================== STRESS TEST ====================

function stressTest(): void
{
    echo "\n=== Stress Test ===\n";

    $taskCounts = [100, 500, 1000, 5000];

    foreach ($taskCounts as $count) {
        echo "\nTesting {$count} concurrent tasks...\n";

        $urls = [];
        for ($i = 1; $i <= $count; $i++) {
            $urls[] = "https://stress-test.com/page-{$i}";
        }

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        try {
            $vosaka = new VOsakaWebCrawler();
            $vosaka->crawlUrls($urls);

            $endTime = microtime(true);
            $endMemory = memory_get_peak_usage();

            $duration = $endTime - $startTime;
            $memoryUsed = ($endMemory - $startMemory) / 1024 / 1024;
            $tasksPerSecond = $count / $duration;

            echo "Completed {$count} tasks in " . round($duration, 2) . "s\n";
            echo "Tasks/sec: " . round($tasksPerSecond, 2) . "\n";
            echo "Memory used: " . round($memoryUsed, 2) . " MB\n";
            echo "Memory per task: " . round($memoryUsed / $count * 1024, 2) . " KB\n";

        } catch (Exception $e) {
            echo "Failed at {$count} tasks: " . $e->getMessage() . "\n";
            break;
        }
    }
}

// Run tests
benchmarkCrawlers();
memoryLeakTest();
stressTest();