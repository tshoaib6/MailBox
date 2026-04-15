<?php

declare(strict_types=1);

namespace App\Support\Campaigns;

use Carbon\CarbonImmutable;
use Throwable;

final class SendResult
{
    private CarbonImmutable $attemptedAt;

    public function __construct(
        private readonly string $to,
        private readonly string $status,
        private readonly ?int $smtpCode,
        private readonly ?string $smtpMessage,
        private readonly ?string $errorDetail,
        private readonly ?string $messageId = null,
        ?CarbonImmutable $attemptedAt = null,
    ) {
        $this->attemptedAt = $attemptedAt ?? CarbonImmutable::now('UTC');
    }

    public static function fromSmtpSuccess(string $to, array $smtpSession, ?string $messageId = null): self
    {
        $response = $smtpSession['data_final'] ?? self::lastRecipientResponse($smtpSession);
        $code = $response['code'] ?? null;
        $message = $response['message'] ?? null;

        return new self(
            to: $to,
            // 250 means the receiving server accepted the message for delivery.
            status: $code === 250 ? 'delivered' : 'error',
            smtpCode: $code,
            smtpMessage: $message,
            errorDetail: null,
            messageId: $messageId,
        );
    }

    public static function fromGenericSuccess(string $to, ?string $messageId = null): self
    {
        return new self(
            to: $to,
            status: 'delivered',
            smtpCode: null,
            smtpMessage: null,
            errorDetail: null,
            messageId: $messageId,
        );
    }

    public static function fromFailure(string $to, Throwable $exception, array $smtpSession = [], ?string $messageId = null): self
    {
        $response = $smtpSession['last_error']
            ?? $smtpSession['data_final']
            ?? $smtpSession['data_ready']
            ?? self::lastRecipientResponse($smtpSession)
            ?: null;

        $code = $response['code'] ?? null;
        $message = $response['message'] ?? self::normaliseExceptionMessage($exception->getMessage());

        return new self(
            to: $to,
            // 4xx/5xx responses are explicit SMTP rejections; missing codes stay transport errors.
            status: self::resolveStatusFromCode($code),
            smtpCode: $code,
            smtpMessage: $message,
            errorDetail: self::normaliseExceptionMessage($exception->getMessage()),
            messageId: $messageId,
        );
    }

    public function toArray(): array
    {
        return [
            'to' => $this->to,
            'status' => $this->status,
            'smtp_code' => $this->smtpCode,
            'smtp_message' => $this->smtpMessage,
            'timestamp' => $this->attemptedAt->toIso8601String(),
            'error_detail' => $this->errorDetail,
        ];
    }

    public function toPersistenceAttributes(): array
    {
        return [
            'attempted_at' => $this->attemptedAt,
            'send_status' => $this->status,
            'smtp_status' => self::resolveSmtpStatus($this->smtpCode),
            'smtp_code' => $this->smtpCode,
            'smtp_message' => $this->smtpMessage,
            'error_detail' => $this->errorDetail,
        ];
    }

    public function attemptedAt(): CarbonImmutable
    {
        return $this->attemptedAt;
    }

    public function messageId(): ?string
    {
        return $this->messageId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function smtpCode(): ?int
    {
        return $this->smtpCode;
    }

    public function errorDetail(): ?string
    {
        return $this->errorDetail;
    }

    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }

    public static function resolveSmtpStatus(?int $code): ?string
    {
        if ($code === null) {
            return null;
        }

        // 250 indicates the message was accepted by the SMTP server.
        if ($code === 250) {
            return 'Accepted';
        }

        // 421 means the SMTP service is unavailable and the attempt can be retried later.
        if ($code === 421) {
            return 'Server unavailable';
        }

        // 535 means authentication failed before the server would accept mail.
        if ($code === 535) {
            return 'Auth failed';
        }

        // 4xx responses are transient SMTP failures and are generally retryable.
        if ($code >= 400 && $code < 500) {
            return 'Temporary failure (retryable)';
        }

        // 5xx responses are permanent SMTP failures and usually represent hard bounces.
        if ($code >= 500 && $code < 600) {
            return 'Permanent failure (hard bounce)';
        }

        return 'SMTP response';
    }

    private static function resolveStatusFromCode(?int $code): string
    {
        if ($code !== null && $code >= 400 && $code < 600) {
            return 'rejected';
        }

        return 'error';
    }

    private static function normaliseExceptionMessage(string $message): string
    {
        return trim(preg_replace('/\s+/', ' ', $message) ?? $message);
    }

    private static function lastRecipientResponse(array $smtpSession): ?array
    {
        $responses = $smtpSession['rcpt'] ?? [];

        if (! is_array($responses) || $responses === []) {
            return null;
        }

        return $responses[array_key_last($responses)] ?? null;
    }
}