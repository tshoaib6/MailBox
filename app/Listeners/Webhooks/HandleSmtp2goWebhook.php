<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

use App\Events\Webhooks\Smtp2goWebhookReceived;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Sendportal\Base\Services\Webhooks\EmailWebhookService;

class HandleSmtp2goWebhook implements ShouldQueue
{
    /** @var string */
    public $queue = 'sendportal-webhook-process';

    /** @var EmailWebhookService */
    private $emailWebhookService;

    public function __construct(EmailWebhookService $emailWebhookService)
    {
        $this->emailWebhookService = $emailWebhookService;
    }

    /**
     * smtp2go webhook payload fields:
     *   event    – processed | delivered | open | click | bounce | spam | unsubscribe | resubscribe | reject
     *   email_id – the ID we stored in message_id when sending
     *   time     – UTC timestamp string
     *   srchost  – IP address for open/click events
     *   url      – clicked URL (click events only)
     *   bounce   – "hard" or "soft" (bounce events only)
     *   message  – SMTP error description (bounce/reject events)
     */
    public function handle(Smtp2goWebhookReceived $event): void
    {
        $payload   = $event->payload;
        $emailId   = Arr::get($payload, 'email_id');
        $eventName = Arr::get($payload, 'event');

        Log::info('Processing smtp2go webhook.', ['type' => $eventName, 'email_id' => $emailId]);

        switch ($eventName) {
            case 'delivered':
                $this->handleDelivered($emailId, $payload);
                break;

            case 'open':
                $this->handleOpen($emailId, $payload);
                break;

            case 'click':
                $this->handleClick($emailId, $payload);
                break;

            case 'bounce':
                $this->handleBounce($emailId, $payload);
                break;

            case 'spam':
                $this->handleSpam($emailId, $payload);
                break;

            case 'unsubscribe':
                $this->handleUnsubscribe($emailId, $payload);
                break;

            case 'reject':
                $this->handleReject($emailId, $payload);
                break;

            case 'processed':
            case 'resubscribe':
                // No action needed for these events.
                break;

            default:
                Log::warning("Unknown smtp2go webhook event type '{$eventName}'.", ['payload' => $payload]);
        }
    }

    private function handleDelivered(string $emailId, array $payload): void
    {
        $this->emailWebhookService->handleDelivery($emailId, $this->extractTimestamp($payload));
    }

    private function handleOpen(string $emailId, array $payload): void
    {
        $ipAddress = Arr::get($payload, 'srchost');
        $this->emailWebhookService->handleOpen($emailId, $this->extractTimestamp($payload), $ipAddress);
    }

    private function handleClick(string $emailId, array $payload): void
    {
        $url = Arr::get($payload, 'url');
        $this->emailWebhookService->handleClick($emailId, $this->extractTimestamp($payload), $url);
    }

    private function handleBounce(string $emailId, array $payload): void
    {
        $bounceType  = Arr::get($payload, 'bounce', 'hard'); // "hard" or "soft"
        $description = Arr::get($payload, 'message');
        $timestamp   = $this->extractTimestamp($payload);
        $severity    = $bounceType === 'soft' ? 'Temporary' : 'Permanent';

        $this->emailWebhookService->handleFailure($emailId, $severity, $description, $timestamp);

        if ($bounceType === 'hard') {
            $this->emailWebhookService->handlePermanentBounce($emailId, $timestamp);
        }
    }

    private function handleSpam(string $emailId, array $payload): void
    {
        $this->emailWebhookService->handleComplaint($emailId, $this->extractTimestamp($payload));
    }

    private function handleUnsubscribe(string $emailId, array $payload): void
    {
        $this->emailWebhookService->handleComplaint($emailId, $this->extractTimestamp($payload));
    }

    private function handleReject(string $emailId, array $payload): void
    {
        $description = Arr::get($payload, 'message', 'Rejected by smtp2go');
        $this->emailWebhookService->handleFailure($emailId, 'Permanent', $description, $this->extractTimestamp($payload));
        $this->emailWebhookService->handlePermanentBounce($emailId, $this->extractTimestamp($payload));
    }

    private function extractTimestamp(array $payload): Carbon
    {
        $time = Arr::get($payload, 'time');
        return $time ? Carbon::parse($time) : Carbon::now();
    }
}
