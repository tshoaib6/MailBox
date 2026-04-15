<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Campaigns;

use App\Support\Campaigns\SendResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SendResultTest extends TestCase
{
    #[Test]
    public function it_maps_a_250_data_response_to_delivered(): void
    {
        $result = SendResult::fromSmtpSuccess('alice@example.com', [
            'data_final' => [
                'code' => 250,
                'message' => 'OK: queued as abc123',
            ],
        ], 'abc123');

        $this->assertSame([
            'to' => 'alice@example.com',
            'status' => 'delivered',
            'smtp_code' => 250,
            'smtp_message' => 'OK: queued as abc123',
            'timestamp' => $result->attemptedAt()->toIso8601String(),
            'error_detail' => null,
        ], $result->toArray());

        $this->assertSame('Accepted', SendResult::resolveSmtpStatus($result->smtpCode()));
    }

    #[Test]
    public function it_maps_535_auth_failures_to_rejected_results_with_error_detail(): void
    {
        $exception = new \RuntimeException('Expected response code "235" but got code "535", with message "535 Authentication credentials invalid".', 535);
        $result = SendResult::fromFailure('bob@example.com', $exception, [
            'last_error' => [
                'code' => 535,
                'message' => 'Authentication credentials invalid',
                'raw' => '535 Authentication credentials invalid',
            ],
        ]);

        $this->assertSame('rejected', $result->status());
        $this->assertSame(535, $result->smtpCode());
        $this->assertSame('Auth failed', SendResult::resolveSmtpStatus($result->smtpCode()));
        $this->assertStringContainsString('535', $result->errorDetail() ?? '');
    }
}