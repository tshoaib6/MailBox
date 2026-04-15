<?php

declare(strict_types=1);

namespace App\Adapters;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Sendportal\Base\Adapters\BaseMailAdapter;
use Sendportal\Base\Services\Messages\MessageTrackingOptions;

class Smtp2goAdapter extends BaseMailAdapter
{
    const API_BASE = 'https://api.smtp2go.com/v3';

    public function send(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $subject,
        MessageTrackingOptions $trackingOptions,
        string $content
    ): string {
        $apiKey = Arr::get($this->config, 'api_key');
        $sender = $fromName ? "{$fromName} <{$fromEmail}>" : $fromEmail;

        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post(self::API_BASE . '/email/send', [
                'api_key'   => $apiKey,
                'sender'    => $sender,
                'to'        => [$toEmail],
                'subject'   => $subject,
                'html_body' => $content,
            ]);

        if (! $response->successful()) {
            $error = $response->json('data.error') ?? $response->body();
            throw new \RuntimeException("smtp2go API error [{$response->status()}]: {$error}");
        }

        $data = $response->json('data') ?? [];

        if (($data['failed'] ?? 0) > 0) {
            $failure = is_array($data['failures'][0] ?? null)
                ? json_encode($data['failures'][0])
                : ($data['failures'][0] ?? 'Unknown failure');
            throw new \RuntimeException("smtp2go send failed: {$failure}");
        }

        return $data['email_id'] ?? '-1';
    }

    /**
     * Fetch account-level statistics from smtp2go.
     * Returns: emails, rejects, softbounces, hardbounces, bounce_percent
     */
    public static function fetchBounceStats(string $apiKey): array
    {
        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post(self::API_BASE . '/stats/email_bounces', [
                'api_key' => $apiKey,
            ]);

        return $response->successful() ? ($response->json('data') ?? []) : [];
    }

    /**
     * Fetch account-level summary from smtp2go.
     * Returns: emails, rejects, spam, bounces, unsubscribes, etc.
     */
    public static function fetchSummaryStats(string $apiKey): array
    {
        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post(self::API_BASE . '/stats/email_summary', [
                'api_key' => $apiKey,
            ]);

        return $response->successful() ? ($response->json('data') ?? []) : [];
    }

    /**
     * Search per-email activity events from smtp2go.
     * $emailIds: array of smtp2go email_ids to look up.
     * Returns array of event objects.
     */
    public static function fetchActivity(string $apiKey, array $emailIds, ?string $startDate = null, ?string $endDate = null): array
    {
        if (empty($emailIds)) {
            return [];
        }

        // smtp2go supports | separated search across email_id field
        $search = implode('|', $emailIds);

        $payload = [
            'api_key'    => $apiKey,
            'search'     => $search,
            'limit'      => 1000,
        ];

        if ($startDate) {
            $payload['start_date'] = $startDate;
        }
        if ($endDate) {
            $payload['end_date'] = $endDate;
        }

        $allEvents = [];
        $continueToken = null;

        do {
            if ($continueToken) {
                $payload['continue_token'] = $continueToken;
            }

            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->post(self::API_BASE . '/activity/search', $payload);

            if (! $response->successful()) {
                break;
            }

            $data   = $response->json('data') ?? [];
            $events = $data['events'] ?? [];
            $allEvents = array_merge($allEvents, $events);
            $continueToken = $data['continue_token'] ?? null;

        } while ($continueToken && count($allEvents) < 5000);

        return $allEvents;
    }
}
