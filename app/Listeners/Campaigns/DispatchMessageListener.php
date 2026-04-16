<?php

declare(strict_types=1);

namespace App\Listeners\Campaigns;

use Sendportal\Base\Events\MessageDispatchEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class DispatchMessageListener implements ShouldQueue
{
    /** @var string */
    public $queue = 'sendportal-emails';

    /**
     * Handle the message dispatch event.
     *
     * This listener ensures messages are dispatched with proper timing,
     * maintaining the 1-minute gap between each email in a campaign.
     */
    public function handle(MessageDispatchEvent $event): void
    {
        $message = $event->message;

        Log::info('Dispatching message via queue', [
            'message_id' => $message->id,
            'campaign_id' => $message->source_id,
            'recipient' => $message->recipient_email,
        ]);

        try {
            $dispatchService = app(\App\Services\Messages\DispatchMessage::class);
            $dispatchService->handle($message);
        } catch (\Throwable $exception) {
            Log::error(
                'Message dispatch failed in listener',
                [
                    'message_id' => $message->id,
                    'campaign_id' => $message->source_id,
                    'email' => $message->recipient_email,
                    'error' => $exception->getMessage(),
                    'trace' => $exception->getTraceAsString(),
                ]
            );

            throw $exception;
        }
    }
}
