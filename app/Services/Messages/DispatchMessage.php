<?php

declare(strict_types=1);

namespace App\Services\Messages;

use App\Adapters\SmtpAdapter;
use App\Support\Campaigns\SendResult;
use Illuminate\Support\Facades\Log;
use Sendportal\Base\Factories\MailAdapterFactory;
use Sendportal\Base\Models\Message;
use Sendportal\Base\Services\Messages\DispatchMessage as BaseDispatchMessage;
use Sendportal\Base\Services\Messages\MessageOptions;
use Sendportal\Base\Services\Messages\MessageTrackingOptions;

class DispatchMessage extends BaseDispatchMessage
{
    public function __construct(
        \Sendportal\Base\Services\Content\MergeContentService $mergeContentService,
        \Sendportal\Base\Services\Content\MergeSubjectService $mergeSubjectService,
        \Sendportal\Base\Services\Messages\ResolveEmailService $resolveEmailService,
        \Sendportal\Base\Services\Messages\RelayMessage $relayMessage,
        \Sendportal\Base\Services\Messages\MarkAsSent $markAsSent,
        protected MailAdapterFactory $mailAdapterFactory,
    ) {
        parent::__construct($mergeContentService, $mergeSubjectService, $resolveEmailService, $relayMessage, $markAsSent);
    }

    public function handle(Message $message): ?string
    {
        if (! $this->isValidMessage($message)) {
            Log::info('Message is not valid, skipping id=' . $message->id);

            return null;
        }

        $message = $this->mergeSubject($message);
        $mergedContent = $this->getMergedContent($message);
        $emailService = $this->getEmailService($message);
        $trackingOptions = MessageTrackingOptions::fromMessage($message);

        try {
            $sendResult = $this->dispatchWithResult($message, $emailService, $trackingOptions, $mergedContent);
        } catch (\Throwable $exception) {
            Log::error(
                'Recipient dispatch failed campaign=' . $message->source_id
                . ' message=' . $message->id
                . ' email=' . $message->recipient_email
                . ' error=' . $exception->getMessage()
                . ' trace=' . $exception->getTraceAsString(),
                ['exception' => $exception]
            );

            $sendResult = SendResult::fromFailure($message->recipient_email, $exception);
        }

        $this->persistResult($message, $sendResult);

        Log::info('Message dispatch result recorded.', $sendResult->toArray());

        return $sendResult->messageId();
    }

    protected function dispatchWithResult(Message $message, \Sendportal\Base\Models\EmailService $emailService, MessageTrackingOptions $trackingOptions, string $mergedContent): SendResult
    {
        $adapter = $this->mailAdapterFactory->adapter($emailService);

        $messageOptions = (new MessageOptions())
            ->setTo($message->recipient_email)
            ->setFromEmail($message->from_email)
            ->setFromName($message->from_name)
            ->setSubject($message->subject)
            ->setTrackingOptions($trackingOptions);

        try {
            $messageId = $adapter->send(
                $messageOptions->getFromEmail(),
                $messageOptions->getFromName(),
                $messageOptions->getTo(),
                $messageOptions->getSubject(),
                $messageOptions->getTrackingOptions(),
                $mergedContent,
            );
        } catch (\Throwable $exception) {
            if ($adapter instanceof SmtpAdapter && $adapter->getLastSendResult()) {
                Log::error(
                    'Recipient dispatch failed campaign=' . $message->source_id
                    . ' message=' . $message->id
                    . ' email=' . $message->recipient_email
                    . ' error=' . $exception->getMessage()
                    . ' trace=' . $exception->getTraceAsString(),
                    ['exception' => $exception]
                );

                return $adapter->getLastSendResult();
            }

            throw $exception;
        }

        if ($adapter instanceof SmtpAdapter && $adapter->getLastSendResult()) {
            return $adapter->getLastSendResult();
        }

        return SendResult::fromGenericSuccess($message->recipient_email, $messageId);
    }

    protected function persistResult(Message $message, SendResult $sendResult): Message
    {
        if ($sendResult->isDelivered()) {
            $message = $this->markSent($message, $sendResult->messageId() ?? '-1');
        }

        foreach ($sendResult->toPersistenceAttributes() as $attribute => $value) {
            $message->{$attribute} = $value;
        }

        if (! $sendResult->isDelivered() && $sendResult->smtpCode() !== null && $sendResult->smtpCode() >= 500) {
            $message->bounced_at = $message->bounced_at ?: $sendResult->attemptedAt();
        }

        return tap($message)->save();
    }
}