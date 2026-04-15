<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Adapters\Smtp2goAdapter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sendportal\Base\Models\UnsubscribeEventType;

class Smtp2goSyncActivity extends Command
{
    protected $signature   = 'smtp2go:sync-activity {--days=3 : How many days back to sync}';
    protected $description = 'Pull per-email event data from smtp2go and update message delivery/open/click/bounce records';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        // Find all smtp2go email services (type_id = 8)
        $services = DB::table('sendportal_email_services')
            ->where('type_id', 8)
            ->get(['id', 'settings']);

        if ($services->isEmpty()) {
            $this->info('No smtp2go email services configured.');
            return 0;
        }

        $startDate = Carbon::now('UTC')->subDays($days)->startOfDay()->toIso8601String();
        $endDate   = Carbon::now('UTC')->toIso8601String();

        $totalUpdated = 0;

        foreach ($services as $service) {
            $settings = json_decode($service->settings, true);
            if (empty($settings)) {
                // Settings are Eloquent-encrypted — load via model
                $model    = \Sendportal\Base\Models\EmailService::find($service->id);
                $settings = $model ? $model->settings : [];
            }

            $apiKey = $settings['api_key'] ?? null;
            if (! $apiKey) {
                $this->warn("Service ID {$service->id}: no api_key found, skipping.");
                continue;
            }

            // Load messages sent via this workspace, keyed by smtp2go email_id
            $messages = DB::table('sendportal_messages')
                ->where('workspace_id', function ($q) use ($service) {
                    $q->select('workspace_id')
                      ->from('sendportal_email_services')
                      ->where('id', $service->id)
                      ->limit(1);
                })
                ->whereNotNull('message_id')
                ->whereNotNull('sent_at')
                ->where('sent_at', '>=', Carbon::now()->subDays($days))
                ->get(['id', 'subscriber_id', 'source_type', 'source_id', 'message_id',
                       'delivered_at', 'opened_at', 'clicked_at', 'ip',
                       'bounced_at', 'complained_at', 'unsubscribed_at',
                       'open_count', 'click_count']);

            if ($messages->isEmpty()) {
                $this->info("Service ID {$service->id}: no messages to sync.");
                continue;
            }

            $this->info("Service ID {$service->id}: syncing " . $messages->count() . " messages...");

            // Build lookup: smtp2go email_id → message DB row
            $byEmailId = [];
            foreach ($messages as $msg) {
                $byEmailId[$msg->message_id] = $msg;
            }

            // Fetch all events from smtp2go (paginated internally)
            $events = Smtp2goAdapter::fetchActivity($apiKey, array_keys($byEmailId), $startDate, $endDate);

            if (empty($events)) {
                $this->info("Service ID {$service->id}: no events returned from smtp2go.");
                continue;
            }

            // Group full event objects by email_id and event type
            $eventsByEmailId = [];
            foreach ($events as $event) {
                $emailId   = $event['email_id'] ?? null;
                $eventType = $event['event']    ?? null;

                if (! $emailId || ! $eventType || ! isset($byEmailId[$emailId])) {
                    continue;
                }

                $eventsByEmailId[$emailId][$eventType][] = $event;
            }

            foreach ($eventsByEmailId as $emailId => $eventGroups) {
                $msg    = $byEmailId[$emailId];
                $update = [];

                // ---------- DELIVERED ----------
                if (isset($eventGroups['delivered']) && ! $msg->delivered_at) {
                    $earliest = $this->earliestDate($eventGroups['delivered']);
                    if ($earliest) {
                        $update['delivered_at'] = $earliest;
                    }
                }

                // ---------- OPENED ----------
                if (isset($eventGroups['opened'])) {
                    $openEvents = $eventGroups['opened'];
                    $newCount   = count($openEvents);

                    if (! $msg->opened_at) {
                        $earliest = $this->earliestDate($openEvents);
                        if ($earliest) {
                            $update['opened_at'] = $earliest;
                            // Store opener IP from first open event if available
                            $firstOpen = $this->earliestEvent($openEvents);
                            $ip = $firstOpen['ip'] ?? $firstOpen['outbound_ip'] ?? null;
                            if ($ip && ! $msg->ip) {
                                $update['ip'] = $ip;
                            }
                        }
                    }

                    if ($newCount > (int) $msg->open_count) {
                        $update['open_count'] = $newCount;
                    }
                }

                // ---------- CLICKED ----------
                if (isset($eventGroups['clicked'])) {
                    $clickEvents = $eventGroups['clicked'];
                    $newCount    = count($clickEvents);

                    if (! $msg->clicked_at) {
                        $earliest = $this->earliestDate($clickEvents);
                        if ($earliest) {
                            $update['clicked_at'] = $earliest;
                        }
                    }

                    if ($newCount > (int) $msg->click_count) {
                        $update['click_count'] = $newCount;
                    }

                    // Upsert per-URL click counts into sendportal_message_urls
                    $this->syncMessageUrls($msg, $clickEvents);
                }

                // ---------- SOFT BOUNCE ----------
                if (isset($eventGroups['soft-bounced']) && ! $msg->bounced_at) {
                    $earliest = $this->earliestDate($eventGroups['soft-bounced']);
                    if ($earliest) {
                        $update['bounced_at'] = $earliest;
                        $firstEvent = $this->earliestEvent($eventGroups['soft-bounced']);
                        $description = $firstEvent['smtp_response'] ?? $firstEvent['reason'] ?? null;
                        $this->createMessageFailure($msg->id, 'Temporary', $description, $earliest);
                    }
                }

                // ---------- HARD BOUNCE ----------
                if (isset($eventGroups['hard-bounced']) && ! $msg->bounced_at) {
                    $earliest = $this->earliestDate($eventGroups['hard-bounced']);
                    if ($earliest) {
                        $update['bounced_at'] = $earliest;
                        $firstEvent = $this->earliestEvent($eventGroups['hard-bounced']);
                        $description = $firstEvent['smtp_response'] ?? $firstEvent['reason'] ?? null;
                        $this->createMessageFailure($msg->id, 'Permanent', $description, $earliest);
                        // Auto-unsubscribe the recipient (permanent bounce = undeliverable)
                        $this->unsubscribeSubscriber($msg->subscriber_id, UnsubscribeEventType::BOUNCE, $earliest);
                    }
                }

                // ---------- SPAM / COMPLAINT ----------
                if (isset($eventGroups['spam']) && ! $msg->complained_at) {
                    $earliest = $this->earliestDate($eventGroups['spam']);
                    if ($earliest) {
                        $update['complained_at'] = $earliest;
                        // Auto-unsubscribe the recipient (spam complaint)
                        $this->unsubscribeSubscriber($msg->subscriber_id, UnsubscribeEventType::COMPLAINT, $earliest);
                    }
                }

                // ---------- UNSUBSCRIBED ----------
                if (isset($eventGroups['unsubscribed']) && ! $msg->unsubscribed_at) {
                    $earliest = $this->earliestDate($eventGroups['unsubscribed']);
                    if ($earliest) {
                        $update['unsubscribed_at'] = $earliest;
                        $this->unsubscribeSubscriber($msg->subscriber_id, UnsubscribeEventType::MANUAL_BY_SUBSCRIBER, $earliest);
                    }
                }

                if (! empty($update)) {
                    $update['updated_at'] = now();
                    DB::table('sendportal_messages')->where('id', $msg->id)->update($update);
                    $totalUpdated++;
                }
            }

            $this->info("Service ID {$service->id}: updated {$totalUpdated} message records.");
            Log::info("smtp2go:sync-activity service={$service->id} updated={$totalUpdated}");
        }

        $this->info("Sync complete. Total updated: {$totalUpdated}");
        return 0;
    }

    /**
     * Upsert click tracking rows into sendportal_message_urls.
     * Each unique URL clicked per campaign source gets a count.
     */
    private function syncMessageUrls(object $msg, array $clickEvents): void
    {
        // Group click events by URL
        $urlCounts = [];
        foreach ($clickEvents as $event) {
            $url = $event['url'] ?? null;
            if (! $url) {
                continue;
            }
            $urlCounts[$url] = ($urlCounts[$url] ?? 0) + 1;
        }

        foreach ($urlCounts as $url => $count) {
            $hash = md5($msg->source_type . '_' . $msg->source_id . '_' . $url);

            $existing = DB::table('sendportal_message_urls')->where('hash', $hash)->first();

            if ($existing) {
                if ($count > (int) $existing->click_count) {
                    DB::table('sendportal_message_urls')
                        ->where('hash', $hash)
                        ->update(['click_count' => $count, 'updated_at' => now()]);
                }
            } else {
                DB::table('sendportal_message_urls')->insert([
                    'hash'        => $hash,
                    'source_type' => $msg->source_type,
                    'source_id'   => $msg->source_id,
                    'url'         => $url,
                    'click_count' => $count,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }
    }

    /**
     * Create a MessageFailure record for a bounce, avoiding duplicates.
     */
    private function createMessageFailure(int $messageId, string $severity, ?string $description, string $failedAt): void
    {
        $exists = DB::table('sendportal_message_failures')
            ->where('message_id', $messageId)
            ->where('severity', $severity)
            ->exists();

        if (! $exists) {
            DB::table('sendportal_message_failures')->insert([
                'message_id'  => $messageId,
                'severity'    => $severity,
                'description' => $description,
                'failed_at'   => Carbon::parse($failedAt)->toDateTimeString(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    /**
     * Mark a subscriber as unsubscribed if they aren't already.
     */
    private function unsubscribeSubscriber(int $subscriberId, int $eventTypeId, string $timestamp): void
    {
        DB::table('sendportal_subscribers')
            ->where('id', $subscriberId)
            ->whereNull('unsubscribed_at')
            ->update([
                'unsubscribed_at'      => Carbon::parse($timestamp)->toDateTimeString(),
                'unsubscribe_event_id' => $eventTypeId,
                'updated_at'           => now(),
            ]);
    }

    /**
     * Return the earliest ISO date string from an array of event objects.
     */
    private function earliestDate(array $events): ?string
    {
        $dates = array_filter(array_column($events, 'date'));
        return empty($dates) ? null : min($dates);
    }

    /**
     * Return the event object with the earliest date.
     */
    private function earliestEvent(array $events): array
    {
        usort($events, fn($a, $b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));
        return $events[0] ?? [];
    }
}
