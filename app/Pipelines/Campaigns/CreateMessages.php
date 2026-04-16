<?php

declare(strict_types=1);

namespace App\Pipelines\Campaigns;

use Sendportal\Base\Events\MessageDispatchEvent;
use Sendportal\Base\Models\Campaign;
use Sendportal\Base\Models\Message;
use Sendportal\Base\Models\Subscriber;
use Sendportal\Base\Models\Tag;

class CreateMessages extends \Sendportal\Base\Pipelines\Campaigns\CreateMessages
{
    /**
     * Handle a campaign where all subscribers have been selected.
     * If the campaign has a contact list, dispatch only to that list.
     *
     * @throws \Exception
     */
    protected function handleAllSubscribers(Campaign $campaign)
    {
        Subscriber::where('workspace_id', $campaign->workspace_id)
            ->whereNull('unsubscribed_at')
            ->when(isset($campaign->contact_list_id) && $campaign->contact_list_id, function ($query) use ($campaign) {
                $query->where('contact_list_id', $campaign->contact_list_id);
            })
            ->chunkById(1000, function ($subscribers) use ($campaign) {
                $this->dispatchToSubscriber($campaign, $subscribers);
            }, 'id');
    }

    /**
     * Handle each tag, optionally scoped to the campaign's contact list.
     */
    protected function handleTag(Campaign $campaign, Tag $tag): void
    {
        \Log::info('- Handling Campaign Tag id=' . $tag->id);

        $tag->subscribers()
            ->whereNull('unsubscribed_at')
            ->when(isset($campaign->contact_list_id) && $campaign->contact_list_id, function ($query) use ($campaign) {
                $query->where('sendportal_subscribers.contact_list_id', $campaign->contact_list_id);
            })
            ->chunkById(1000, function ($subscribers) use ($campaign) {
                $this->dispatchToSubscriber($campaign, $subscribers);
            }, 'sendportal_subscribers.id');
    }

    /**
     * Dispatch campaign recipients with per-recipient fault tolerance.
     * One bad address/provider error should not stop all remaining recipients.
     *
     * When SEND_RATE_PER_HOUR > 0 each dispatch is throttled so the total
     * sending rate never exceeds that limit.  The sleep accounts for the time
     * the actual API/SMTP call took, keeping the spacing accurate.
     * 
     * Additionally, a fixed 1-minute delay is enforced between each email.
     */
    protected function dispatchToSubscriber(Campaign $campaign, $subscribers)
    {
        \Log::info('- Number of subscribers in this chunk: ' . count($subscribers));

        $ratePerHour   = (int) config('sendportal-host.send_rate_per_hour', 0);
        $intervalMicro = $ratePerHour > 0 ? (int) (3_600_000_000 / $ratePerHour) : 0;
        
        // Fixed 1-minute delay between emails (60 seconds = 60,000,000 microseconds)
        $oneMinuteMicro = 60_000_000;

        foreach ($subscribers as $subscriber) {
            if (! $this->canSendToSubscriber($campaign->id, $subscriber->id)) {
                continue;
            }

            $startMicro = hrtime(true);

            try {
                $this->dispatch($campaign, $subscriber);
            } catch (\Throwable $e) {
                \Log::error('Recipient dispatch failed campaign=' . $campaign->id . ' subscriber=' . $subscriber->id . ' email=' . ($subscriber->email ?? 'unknown') . ' error=' . $e->getMessage());
            }

            // Calculate delay: use the maximum of rate-limited delay and 1-minute fixed delay
            $elapsed = (int) ((hrtime(true) - $startMicro) / 1_000); // ns → µs
            
            $delayMicro = max($intervalMicro, $oneMinuteMicro);
            $remaining = $delayMicro - $elapsed;
            
            if ($remaining > 0) {
                \Log::info('Sleeping for ' . round($remaining / 1_000_000, 2) . ' seconds before next email');
                usleep($remaining);
            }
        }
    }

    /**
     * Dispatch now, but never re-send a message that has already been attempted or sent.
     * Only re-dispatch a message that was saved as draft (queued_at set, attempted_at null, sent_at null).
     */
    protected function dispatchNow(Campaign $campaign, Subscriber $subscriber): Message
    {
        if ($message = $this->findMessage($campaign, $subscriber)) {
            // If already sent or already attempted, do nothing — prevent double-sending.
            if ($message->sent_at !== null || $message->attempted_at !== null) {
                \Log::info('Message already sent/attempted, skipping campaign=' . $campaign->id . ' subscriber=' . $subscriber->id);
                return $message;
            }

            // Only re-dispatch if it's a pure draft (queued_at set, never attempted).
            if ($message->queued_at !== null) {
                \Log::info('Dispatching existing draft message campaign=' . $campaign->id . ' subscriber=' . $subscriber->id);
                event(new MessageDispatchEvent($message));
            }

            return $message;
        }

        return parent::dispatchNow($campaign, $subscriber);
    }
}