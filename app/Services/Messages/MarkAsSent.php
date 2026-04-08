<?php

declare(strict_types=1);

namespace App\Services\Messages;

use Sendportal\Base\Models\Campaign;
use Sendportal\Base\Models\EmailServiceType;
use Sendportal\Base\Models\Message;
use Sendportal\Base\Services\Messages\MarkAsSent as BaseMarkAsSent;

class MarkAsSent extends BaseMarkAsSent
{
    /**
     * Save the external message_id and sent_at timestamp.
     *
     * For SMTP email services there are no delivery webhooks, so we also set
     * delivered_at equal to sent_at whenever the adapter reports a successful
     * send (messageId is not the failure sentinel '-1').
     */
    public function handle(Message $message, string $messageId): Message
    {
        $saved = parent::handle($message, $messageId);

        if ($messageId === '-1' || $saved->delivered_at) {
            return $saved;
        }

        if ($saved->source_type !== Campaign::class) {
            return $saved;
        }

        $campaign = Campaign::with('email_service')->find($saved->source_id);

        if ($campaign && optional($campaign->email_service)->type_id === EmailServiceType::SMTP) {
            $saved->delivered_at = $saved->sent_at;
            $saved->save();
        }

        return $saved;
    }
}
