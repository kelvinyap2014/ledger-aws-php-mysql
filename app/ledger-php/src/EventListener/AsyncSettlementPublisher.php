<?php

namespace App\EventListener;

use App\DTO\SettlementEvent;
use App\Service\SqsSettlementPublisher;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

class AsyncSettlementPublisher
{
    /** @var SettlementEvent[] */
    private array $pendingEvents = [];

    public function __construct(
        private SqsSettlementPublisher $sqsPublisher,
    ) {}

    /**
     * Queue a settlement event for async publishing
     */
    public function queue(SettlementEvent $event): void
    {
        $this->pendingEvents[] = $event;
    }

    /**
     * Publish pending SQS events after HTTP response is sent
     * This ensures the client receives a fast response while async work continues
     */
    public function onKernelTerminate(TerminateEvent $event): void
    {
        // Only process on the main request, not subrequests
        if (!$event->isMainRequest()) {
            return;
        }

        try {
            $this->flushPendingEvents();
        } catch (\Exception $e) {
            // Log error but don't throw - response already sent
            error_log("Failed to publish settlement events: " . $e->getMessage());
        }
    }

    /**
     * Publish all pending events to SQS
     */
    private function flushPendingEvents(): void
    {
        foreach ($this->pendingEvents as $event) {
            $this->sqsPublisher->publish($event);
        }
        $this->pendingEvents = [];
    }
}
