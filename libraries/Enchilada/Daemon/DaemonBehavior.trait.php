<?php
/**
 * Enchilada Framework - Daemon Behavior Trait
 * 
 * Provides common functionality for long-running PHP processes:
 * - Signal handling for graceful shutdown
 * - Health check file with metrics
 * - Memory management with periodic GC
 * - Configurable limits and intervals
 * 
 * @author Daniel Morante
 * @copyright 2026 The Daniel Morante Company, Inc.
 * @license BSD-3-Clause
 */

namespace Enchilada\Daemon;

trait DaemonBehavior
{
    /** @var bool Flag to control main loop */
    protected bool $daemonRunning = true;
    
    /** @var string Path to health check file */
    protected string $healthFile = '/tmp/enchilada-daemon.health';
    
    /** @var int Memory limit in MB before graceful exit */
    protected int $maxMemoryMb = 128;
    
    /** @var int Seconds between health file updates */
    protected int $healthInterval = 30;
    
    /** @var int Seconds between garbage collection */
    protected int $gcInterval = 300;
    
    /** @var int Last health update timestamp */
    private int $lastHealthUpdate = 0;
    
    /** @var int Last GC timestamp */
    private int $lastGc = 0;
    
    /** @var array Runtime metrics */
    protected array $metrics = [];
    
    /**
     * Initialize daemon behavior
     * Call this in your daemon's startup
     */
    protected function initDaemon(array $options = []): void
    {
        // Apply options
        if (!empty($options['health_file'])) {
            $this->healthFile = $options['health_file'];
        }
        if (!empty($options['max_memory_mb'])) {
            $this->maxMemoryMb = (int)$options['max_memory_mb'];
        }
        if (!empty($options['health_interval'])) {
            $this->healthInterval = (int)$options['health_interval'];
        }
        if (!empty($options['gc_interval'])) {
            $this->gcInterval = (int)$options['gc_interval'];
        }
        
        // Initialize metrics
        $this->metrics = [
            'started_at' => date('c'),
            'processed' => 0,
            'errors' => 0,
            'last_activity_at' => null,
        ];
        
        $this->lastGc = time();
        
        // Register signal handlers
        $this->registerSignals();
    }
    
    /**
     * Register signal handlers for graceful shutdown
     */
    protected function registerSignals(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        
        $handler = function ($signo) {
            $this->handleSignal($signo);
        };
        
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGHUP, $handler);
    }
    
    /**
     * Handle incoming signal
     */
    protected function handleSignal(int $signo): void
    {
        $signals = [
            SIGTERM => 'SIGTERM',
            SIGINT => 'SIGINT',
            SIGHUP => 'SIGHUP',
        ];
        $name = $signals[$signo] ?? $signo;
        
        $this->logDaemon("Received $name, initiating shutdown...");
        $this->daemonRunning = false;
    }
    
    /**
     * Process pending signals
     * Call this in your main loop
     */
    protected function processSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
    
    /**
     * Perform daemon maintenance tasks
     * Call this in your main loop
     * 
     * @return bool False if daemon should stop
     */
    protected function daemonTick(): bool
    {
        $this->processSignals();
        
        if (!$this->daemonRunning) {
            return false;
        }
        
        $this->updateHealthFile();
        $this->checkMemoryUsage();
        
        return $this->daemonRunning;
    }
    
    /**
     * Update health check file with current status
     */
    protected function updateHealthFile(): void
    {
        $now = time();
        if ($now - $this->lastHealthUpdate < $this->healthInterval) {
            return;
        }
        
        $health = [
            'status' => 'running',
            'pid' => getmypid(),
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'metrics' => $this->metrics,
            'updated_at' => date('c'),
        ];
        
        // Allow subclass to add custom health data
        if (method_exists($this, 'getCustomHealthData')) {
            $health = array_merge($health, $this->getCustomHealthData());
        }
        
        file_put_contents($this->healthFile, json_encode($health, JSON_PRETTY_PRINT));
        $this->lastHealthUpdate = $now;
    }
    
    /**
     * Check memory usage and trigger GC or exit if needed
     */
    protected function checkMemoryUsage(): void
    {
        $now = time();
        $memoryMb = memory_get_usage(true) / 1024 / 1024;
        
        // Periodic garbage collection
        if ($now - $this->lastGc > $this->gcInterval) {
            gc_collect_cycles();
            $this->lastGc = $now;
        }
        
        // Exit if memory limit exceeded (supervisor should restart)
        if ($memoryMb > $this->maxMemoryMb) {
            $this->logDaemon(sprintf(
                "Memory limit exceeded (%.1f MB > %d MB), exiting for restart",
                $memoryMb,
                $this->maxMemoryMb
            ));
            $this->daemonRunning = false;
        }
    }
    
    /**
     * Increment a metric counter
     */
    protected function incrementMetric(string $key, int $amount = 1): void
    {
        if (!isset($this->metrics[$key])) {
            $this->metrics[$key] = 0;
        }
        $this->metrics[$key] += $amount;
    }
    
    /**
     * Set a metric value
     */
    protected function setMetric(string $key, mixed $value): void
    {
        $this->metrics[$key] = $value;
    }
    
    /**
     * Record activity timestamp
     */
    protected function recordActivity(): void
    {
        $this->metrics['last_activity_at'] = date('c');
    }
    
    /**
     * Clean up daemon resources
     * Call this before exiting
     */
    protected function cleanupDaemon(): void
    {
        @unlink($this->healthFile);
        $this->logDaemon(sprintf(
            "Daemon stopped. Processed: %d, Errors: %d",
            $this->metrics['processed'] ?? 0,
            $this->metrics['errors'] ?? 0
        ));
    }
    
    /**
     * Log a daemon message
     * Override this to customize logging
     */
    protected function logDaemon(string $message): void
    {
        fprintf(STDERR, "[%s] %s\n", date('Y-m-d H:i:s'), $message);
    }
    
    /**
     * Check if daemon should continue running
     */
    protected function isRunning(): bool
    {
        return $this->daemonRunning;
    }
    
    /**
     * Request daemon shutdown
     */
    protected function requestShutdown(): void
    {
        $this->daemonRunning = false;
    }
}
