<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Mail\Transport\TrackingEsmtpTransport;
use App\Support\Campaigns\SendResult;
use Illuminate\Support\Arr;
use Sendportal\Base\Adapters\BaseMailAdapter;
use Sendportal\Base\Services\Messages\MessageTrackingOptions;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class SmtpAdapter extends BaseMailAdapter
{
    protected ?TrackingEsmtpTransport $transport = null;

    protected ?SendResult $lastSendResult = null;

    public function send(string $fromEmail, string $fromName, string $toEmail, string $subject, MessageTrackingOptions $trackingOptions, string $content): string
    {
        $bounceAddress = $this->resolveBounceAddress($fromEmail);
        $message = $this->resolveMessage($subject, $content, $fromEmail, $fromName, $toEmail, $bounceAddress);
        $envelope = new Envelope(new Address($bounceAddress), [new Address($toEmail)]);

        $transport = $this->resolveTransport();

        try {
            $result = $transport->send($message, $envelope);
            $messageId = $this->resolveMessageId($result);

            $this->lastSendResult = SendResult::fromSmtpSuccess(
                $toEmail,
                $transport->getLastSmtpSession(),
                $messageId,
            );

            return $messageId;
        } catch (\Throwable $exception) {
            $this->lastSendResult = SendResult::fromFailure(
                $toEmail,
                $exception,
                $transport->getLastSmtpSession(),
            );

            // Reset the transport so the next send gets a fresh connection.
            // A failed send may leave the SMTP stream in a broken state.
            try {
                $transport->stop();
            } catch (\Throwable) {
                // ignore — stream may already be dead
            }
            $this->transport = null;

            throw $exception;
        }
    }

    public function getLastSendResult(): ?SendResult
    {
        return $this->lastSendResult;
    }

    protected function resolveTransport(): TrackingEsmtpTransport
    {
        if ($this->transport) {
            return $this->transport;
        }

        $host = (string) Arr::get($this->config, 'host');
        $port = (int) Arr::get($this->config, 'port', 25);
        $encryption = Arr::get($this->config, 'encryption');
        $tls = $this->resolveTlsMode($encryption, $port);

        $stream = (new SocketStream())
            ->setHost($host)
            ->setPort($port);

        $timeout = Arr::get($this->config, 'timeout', config('mail.timeout', 10));

        if ($timeout !== null) {
            // Keep the socket timeout below PHP's request timeout so SMTP stalls fail as catchable exceptions.
            $stream->setTimeout((float) $timeout);
        }

        $transport = new TrackingEsmtpTransport($host, $port, $tls, stream: $stream);

        if ($username = Arr::get($this->config, 'username')) {
            $transport->setUsername((string) $username);
        }

        if ($password = Arr::get($this->config, 'password')) {
            $transport->setPassword((string) $password);
        }

        $this->transport = $transport;

        return $this->transport;
    }

    protected function resolveMessage(string $subject, string $content, string $fromEmail, string $fromName, string $toEmail, string $bounceAddress): Email
    {
        return (new Email())
            ->from(new Address($fromEmail, $fromName))
            ->to($toEmail)
            ->returnPath($bounceAddress)
            ->subject($subject)
            ->html($content);
    }

    protected function resolveBounceAddress(string $fromEmail): string
    {
        return (string) (Arr::get($this->config, 'bounce_address')
            ?: config('mail.bounce_address.address')
            ?: $fromEmail);
    }

    protected function resolveMessageId(?SentMessage $result): string
    {
        return $result instanceof SentMessage ? $result->getMessageId() : '-1';
    }

    protected function resolveTlsMode(mixed $encryption, int $port): ?bool
    {
        return match ($encryption) {
            'ssl' => true,
            'tls' => $port === 465 ? true : false,
            default => null,
        };
    }
}