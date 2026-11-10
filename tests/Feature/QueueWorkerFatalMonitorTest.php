<?php

namespace Tests\Feature;

use App\Support\QueueWorkerFatalMonitor;
use Tests\TestCase;

class QueueWorkerFatalMonitorTest extends TestCase
{
    public function test_monitor_only_retains_the_active_job_handler_and_can_be_cleared(): void
    {
        $handled = [];
        QueueWorkerFatalMonitor::watch(function () use (&$handled): void {
            $handled[] = 'old';
        });
        QueueWorkerFatalMonitor::watch(function (array $error) use (&$handled): void {
            $handled[] = $error['message'];
        });

        QueueWorkerFatalMonitor::handleShutdown([
            'type' => E_ERROR,
            'message' => 'simulated fatal',
        ]);

        $this->assertSame(['simulated fatal'], $handled);

        QueueWorkerFatalMonitor::watch(function () use (&$handled): void {
            $handled[] = 'should not run';
        });
        QueueWorkerFatalMonitor::clear();
        QueueWorkerFatalMonitor::handleShutdown([
            'type' => E_ERROR,
            'message' => 'cleared fatal',
        ]);

        $this->assertSame(['simulated fatal'], $handled);
    }
}
