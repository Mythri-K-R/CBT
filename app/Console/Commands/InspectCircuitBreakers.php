<?php

namespace App\Console\Commands;

use App\Services\Infrastructure\CircuitBreakerService;
use Illuminate\Console\Command;

/**
 * Inspect and manage circuit breaker states.
 *
 * USAGE:
 *   php artisan examsphere:circuit-status            Show all circuit states
 *   php artisan examsphere:circuit-status --reset=redis   Force Redis circuit to CLOSED
 *   php artisan examsphere:circuit-status --reset=all     Force all circuits to CLOSED
 *   php artisan examsphere:circuit-status --watch         Refresh every 5 s (Ctrl-C to stop)
 */
class InspectCircuitBreakers extends Command
{
    protected $signature = 'examsphere:circuit-status
                            {--reset=  : Force a circuit to CLOSED (service name or "all")}
                            {--watch   : Continuously refresh every 5 seconds}';

    protected $description = 'Show circuit breaker states for Redis, MySQL, and Queue';

    public function handle(CircuitBreakerService $cb): int
    {
        if ($reset = $this->option('reset')) {
            return $this->doReset($cb, $reset);
        }

        if ($this->option('watch')) {
            return $this->watch($cb);
        }

        $this->printStatus($cb);
        return self::SUCCESS;
    }

    private function doReset(CircuitBreakerService $cb, string $target): int
    {
        $services = $target === 'all'
            ? array_keys(config('resilience.circuit_breakers', []))
            : [$target];

        foreach ($services as $service) {
            if (!array_key_exists($service, config('resilience.circuit_breakers', []))) {
                $this->error("Unknown service: {$service}");
                return self::FAILURE;
            }
            $cb->reset($service);
            $this->info("✓  Circuit [{$service}] reset to CLOSED");
        }

        return self::SUCCESS;
    }

    private function watch(CircuitBreakerService $cb): int
    {
        $this->info('Watching circuit breakers — Ctrl-C to stop');

        while (true) {
            $this->output->write("\033[2J\033[H"); // clear terminal
            $this->printStatus($cb);
            sleep(5);
        }
    }

    private function printStatus(CircuitBreakerService $cb): void
    {
        $states = $cb->getStates();
        $rows   = [];

        foreach ($states as $service => $state) {
            $stateLabel = match ($state['state']) {
                CircuitBreakerService::STATE_CLOSED    => '<info>CLOSED</info>',
                CircuitBreakerService::STATE_OPEN      => '<error>OPEN</error>',
                CircuitBreakerService::STATE_HALF_OPEN => '<comment>HALF_OPEN</comment>',
                default                                => $state['state'],
            };

            $openedAt = $state['opened_at']
                ? now()->createFromTimestamp($state['opened_at'])->diffForHumans()
                : '—';

            $rows[] = [
                $service,
                $stateLabel,
                "{$state['failures']}/{$state['threshold']}",
                $openedAt,
            ];
        }

        $this->table(
            ['Service', 'State', 'Failures', 'Opened At'],
            $rows
        );

        $this->line('  Checked at: ' . now()->toDateTimeString());
    }
}
