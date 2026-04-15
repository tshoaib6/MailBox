<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Campaigns;

use App\Support\Campaigns\CampaignSendReportService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CampaignSendReportServiceTest extends TestCase
{
    #[Test]
    public function it_builds_summary_counts_and_failed_recipient_details(): void
    {
        $service = new CampaignSendReportService();

        $summary = $service->summarise(42, [
            [
                'to' => 'alice@example.com',
                'status' => 'delivered',
                'smtp_code' => 250,
                'smtp_message' => 'OK',
                'timestamp' => '2026-04-13T12:00:00Z',
                'error_detail' => null,
            ],
            [
                'to' => 'bob@example.com',
                'status' => 'rejected',
                'smtp_code' => 550,
                'smtp_message' => 'Mailbox not found',
                'timestamp' => '2026-04-13T12:00:01Z',
                'error_detail' => null,
            ],
            [
                'to' => 'carol@example.com',
                'status' => 'error',
                'smtp_code' => null,
                'smtp_message' => null,
                'timestamp' => '2026-04-13T12:00:02Z',
                'error_detail' => 'Connection timeout',
            ],
        ]);

        $this->assertSame(42, $summary['campaign_id']);
        $this->assertSame(3, $summary['total_sent']);
        $this->assertSame(1, $summary['total_delivered']);
        $this->assertSame(2, $summary['total_failed']);
        $this->assertSame('bob@example.com', $summary['failed_recipients'][0]['to']);
        $this->assertSame('Connection timeout', $summary['failed_recipients'][1]['reason']);
        $this->assertStringContainsString('alice@example.com', $summary['table']);
        $this->assertStringContainsString('carol@example.com', $summary['table']);
        $this->assertStringContainsString('Connection timeout', $summary['table']);
    }
}