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
     */
    protected function dispatchToSubscriber(Campaign $campaign, $subscribers)
    {
        \Log::info('- Number of subscribers in this chunk: ' . count($subscribers));

        foreach ($subscribers as $subscriber) {
            if (! $this->canSendToSubscriber($campaign->id, $subscriber->id)) {
                continue;
            }

            try {
                $this->dispatch($campaign, $subscriber);
            } catch (\Throwable $e) {
                \Log::error('Recipient dispatch failed campaign=' . $campaign->id . ' subscriber=' . $subscriber->id . ' email=' . ($subscriber->email ?? 'unknown') . ' error=' . $e->getMessage());
                continue;
            }
        }
    }

    /**
     * Dispatch now, including previously queued draft messages.
     * This allows a campaign that was queued as draft to be switched to auto-send.
     */
    protected function dispatchNow(Campaign $campaign, Subscriber $subscriber): Message
    {
        if ($message = $this->findMessage($campaign, $subscriber)) {
            if (is_null($message->sent_at)) {
                \Log::info('Dispatching existing queued message campaign=' . $campaign->id . ' subscriber=' . $subscriber->id);
                event(new MessageDispatchEvent($message));
            } else {
                \Log::info('Message already sent campaign=' . $campaign->id . ' subscriber=' . $subscriber->id);
            }

            return $message;
        }

        return parent::dispatchNow($campaign, $subscriber);
    }
}