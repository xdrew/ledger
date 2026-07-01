<?php

declare(strict_types=1);

namespace App\Console;

/**
 * Runs a worker iteration repeatedly until the process is asked to stop
 * (`SIGTERM`/`SIGINT`), sleeping between iterations. The stop flag is checked
 * between iterations and during the sleep, so a shutdown finishes the current
 * iteration cleanly rather than being cut mid-batch.
 */
final class WorkerLoop
{
    private bool $stop = false;

    public function __construct()
    {
        if (\function_exists('pcntl_async_signals') && \function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $handler = function (): void {
                $this->stop = true;
            };
            pcntl_signal(SIGTERM, $handler);
            pcntl_signal(SIGINT, $handler);
        }
    }

    /**
     * @param callable(): void $iteration
     */
    public function run(callable $iteration, float $intervalSeconds): void
    {
        while (!$this->stop) {
            $iteration();
            $this->sleep($intervalSeconds);
        }
    }

    private function sleep(float $seconds): void
    {
        $deadline = microtime(true) + $seconds;

        while (!$this->stop && microtime(true) < $deadline) {
            usleep(50_000);
        }
    }
}
