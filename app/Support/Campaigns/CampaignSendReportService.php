<?php

declare(strict_types=1);

namespace App\Support\Campaigns;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sendportal\Base\Models\Campaign;

class CampaignSendReportService
{
    public function buildForCampaignRun(Campaign $campaign, CarbonInterface $startedAt): array
    {
        $results = DB::table('sendportal_messages')
            ->where('source_type', Campaign::class)
            ->where('source_id', $campaign->id)
            ->whereNotNull('attempted_at')
            ->where('attempted_at', '>=', $startedAt)
            ->orderBy('attempted_at')
            ->orderBy('id')
            ->get([
                'recipient_email',
                'send_status',
                'smtp_code',
                'smtp_message',
                'attempted_at',
                'error_detail',
            ])
            ->map(function ($row) {
                return [
                    'to' => $row->recipient_email,
                    'status' => $row->send_status ?? 'error',
                    'smtp_code' => $row->smtp_code !== null ? (int) $row->smtp_code : null,
                    'smtp_message' => $row->smtp_message,
                    'timestamp' => Carbon::parse($row->attempted_at, 'UTC')->toIso8601String(),
                    'error_detail' => $row->error_detail,
                ];
            })
            ->all();

        return $this->summarise($campaign->id, $results);
    }

    public function summarise(int $campaignId, array $results): array
    {
        $failedRecipients = array_values(array_filter($results, static function (array $result): bool {
            return $result['status'] !== 'delivered';
        }));

        return [
            'campaign_id' => $campaignId,
            'total_sent' => count($results),
            'total_delivered' => count(array_filter($results, static function (array $result): bool {
                return $result['status'] === 'delivered' && $result['smtp_code'] === 250;
            })),
            'total_failed' => count($failedRecipients),
            'failed_recipients' => array_map(static function (array $result): array {
                return [
                    'to' => $result['to'],
                    'code' => $result['smtp_code'],
                    'reason' => $result['error_detail'] ?: ($result['smtp_message'] ?: 'Unknown SMTP failure'),
                ];
            }, $failedRecipients),
            'results' => $results,
            'table' => $this->formatTable($results),
        ];
    }

    public function formatTable(array $results): string
    {
        $lines = [
            'Recipient                  | Status    | Code | Detail',
            '---------------------------|-----------|------|------------------',
        ];

        foreach ($results as $result) {
            $lines[] = sprintf(
                '%-27s| %-10s| %-4s | %s',
                mb_strimwidth($result['to'], 0, 27, ''),
                mb_strimwidth($result['status'], 0, 10, ''),
                $result['smtp_code'] ?? '-',
                $result['error_detail'] ?: ($result['smtp_message'] ?: 'OK')
            );
        }

        return implode(PHP_EOL, $lines);
    }

    public function emit(array $summary): void
    {
        if ($summary['total_sent'] === 0) {
            return;
        }

        $output = implode(PHP_EOL, [
            sprintf('Campaign %d SMTP report', $summary['campaign_id']),
            $summary['table'],
            sprintf(
                'Summary: total sent=%d, delivered=%d, failed=%d',
                $summary['total_sent'],
                $summary['total_delivered'],
                $summary['total_failed']
            ),
        ]);

        foreach (explode(PHP_EOL, $output) as $line) {
            Log::info($line);
        }

        if (app()->runningInConsole() && defined('STDOUT')) {
            fwrite(STDOUT, $output . PHP_EOL);
        }
    }
}