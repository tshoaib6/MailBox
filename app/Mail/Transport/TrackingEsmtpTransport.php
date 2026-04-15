<?php

declare(strict_types=1);

namespace App\Mail\Transport;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;

class TrackingEsmtpTransport extends EsmtpTransport
{
    private array $lastSmtpSession = [];

    public function send(\Symfony\Component\Mime\RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->lastSmtpSession = [
            'commands' => [],
            'rcpt' => [],
            'dsn_supported' => false,
        ];

        return parent::send($message, $envelope);
    }

    public function executeCommand(string $command, array $codes): string
    {
        try {
            $response = parent::executeCommand($command, $codes);
            $this->captureResponse($command, $response);

            return $response;
        } catch (TransportExceptionInterface $exception) {
            $this->captureException($command, $exception);
            throw $exception;
        }
    }

    public function getLastSmtpSession(): array
    {
        return $this->lastSmtpSession;
    }

    protected function doSend(SentMessage $message): void
    {
        try {
            $this->start();

            $this->lastSmtpSession['dsn_supported'] = array_key_exists('DSN', $this->getCapabilities());

            $envelope = $message->getEnvelope();
            $sender = $envelope->getSender()->getEncodedAddress();
            $recipientHasUnicode = $envelope->anyAddressHasUnicodeLocalpart();

            if ($recipientHasUnicode && ! $this->serverSupportsSmtpUtf8()) {
                throw new InvalidArgumentException('Invalid addresses: non-ASCII characters not supported in local-part of email.');
            }

            // 250 means the envelope sender was accepted by the SMTP server.
            $this->executeCommand(
                sprintf("MAIL FROM:<%s>%s\r\n", $sender, $recipientHasUnicode ? ' SMTPUTF8' : ''),
                [250]
            );

            foreach ($envelope->getRecipients() as $recipient) {
                $dsnOptions = $this->lastSmtpSession['dsn_supported'] ? ' NOTIFY=SUCCESS,FAILURE' : '';

                // 250/251/252 mean the recipient was accepted for delivery or forwarding.
                $this->executeCommand(
                    sprintf("RCPT TO:<%s>%s\r\n", $recipient->getEncodedAddress(), $dsnOptions),
                    [250, 251, 252]
                );
            }

            // 354 means the server is ready to receive the message body after DATA.
            $this->executeCommand("DATA\r\n", [354]);

            foreach (AbstractStream::replace("\r\n.", "\r\n..", $message->toIterable()) as $chunk) {
                $this->getStream()->write($chunk, false);
            }

            $this->getStream()->flush();

            // 250 after <CRLF>.<CRLF> means the full message was accepted and queued.
            $mtaResult = $this->executeCommand("\r\n.\r\n", [250]);
            $message->appendDebug($this->getStream()->getDebug());

            if ($mtaResult && $messageId = $this->parseMessageId($mtaResult)) {
                $message->setMessageId($messageId);
            }
        } catch (TransportExceptionInterface $exception) {
            $exception->appendDebug($this->getStream()->getDebug());
            throw $exception;
        }
    }

    private function captureResponse(string $command, string $rawResponse): void
    {
        $entry = [
            'command' => $this->normaliseCommandName($command),
            'code' => $this->extractResponseCode($rawResponse),
            'message' => $this->extractResponseMessage($rawResponse),
            'raw' => trim($rawResponse),
        ];

        $this->lastSmtpSession['commands'][] = $entry;

        $key = $this->resolveSessionKey($command);

        if ($key === 'rcpt') {
            $this->lastSmtpSession['rcpt'][] = $entry;
        } elseif ($key !== null) {
            $this->lastSmtpSession[$key] = $entry;
        }

        if (($entry['code'] ?? 0) >= 400) {
            $this->lastSmtpSession['last_error'] = $entry;
        }
    }

    private function captureException(string $command, TransportExceptionInterface $exception): void
    {
        $code = $exception->getCode() > 0 ? (int) $exception->getCode() : null;
        $raw = $this->extractRawResponseFromException($exception->getMessage());

        if ($raw === null && $code === null) {
            return;
        }

        $entry = [
            'command' => $this->normaliseCommandName($command),
            'code' => $code,
            'message' => $raw !== null ? $this->extractResponseMessage($raw) : $exception->getMessage(),
            'raw' => $raw !== null ? trim($raw) : trim($exception->getMessage()),
        ];

        $this->lastSmtpSession['last_error'] = $entry;
        $this->lastSmtpSession['commands'][] = $entry;
    }

    private function resolveSessionKey(string $command): ?string
    {
        $command = strtoupper(trim($command));

        return match (true) {
            str_starts_with($command, 'RCPT TO:') => 'rcpt',
            str_starts_with($command, 'MAIL FROM:') => 'mail_from',
            $command === 'DATA' => 'data_ready',
            $command === '.' => 'data_final',
            str_starts_with($command, 'AUTH ') => 'auth',
            default => null,
        };
    }

    private function normaliseCommandName(string $command): string
    {
        $command = strtoupper(trim($command));

        return $command === '.' ? 'DATA_END' : (strtok($command, ' ') ?: $command);
    }

    private function extractResponseCode(string $rawResponse): ?int
    {
        return preg_match('/^(\d{3})/m', trim($rawResponse), $matches) ? (int) $matches[1] : null;
    }

    private function extractResponseMessage(string $rawResponse): string
    {
        return trim((string) preg_replace('/^\d{3}[ -]?/m', '', trim($rawResponse)));
    }

    private function extractRawResponseFromException(string $message): ?string
    {
        return preg_match('/with message "(?P<response>.+)"\.$/U', $message, $matches)
            ? $matches['response']
            : null;
    }
}