<?php

declare(strict_types=1);

namespace App\Services\Reputation;

use Illuminate\Support\Facades\Log;
use Sendportal\Base\Models\Message;
use Sendportal\Base\Models\Subscriber;
use Carbon\Carbon;

/**
 * Sender Reputation Service
 * 
 * Monitors and protects sender reputation by tracking bounce rates,
 * complaint rates, and implementing automatic list hygiene.
 */
class SenderReputationService
{
    /**
     * Maximum acceptable hard bounce rate (5%)
     */
    private const MAX_HARD_BOUNCE_RATE = 0.05;

    /**
     * Maximum acceptable complaint rate (0.1%)
     */
    private const MAX_COMPLAINT_RATE = 0.001;

    /**
     * Days of inactivity before marking subscriber as inactive
     */
    private const INACTIVITY_THRESHOLD_DAYS = 90;

    /**
     * Check if sending should be halted due to high bounce rate
     */
    public function shouldHaltSending(int $workspaceId): bool
    {
        $bounceRate = $this->calculateBounceRate($workspaceId);
        
        if ($bounceRate > self::MAX_HARD_BOUNCE_RATE) {
            Log::warning('High bounce rate detected', [
                'workspace_id' => $workspaceId,
                'bounce_rate' => $bounceRate,
                'threshold' => self::MAX_HARD_BOUNCE_RATE,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Check if sending should be warned due to high complaint rate
     */
    public function getComplaintWarning(int $workspaceId): ?array
    {
        $complaintRate = $this->calculateComplaintRate($workspaceId);
        
        if ($complaintRate > self::MAX_COMPLAINT_RATE) {
            Log::warning('High complaint rate detected', [
                'workspace_id' => $workspaceId,
                'complaint_rate' => $complaintRate,
                'threshold' => self::MAX_COMPLAINT_RATE,
            ]);

            return [
                'warning' => true,
                'rate' => $complaintRate,
                'threshold' => self::MAX_COMPLAINT_RATE,
            ];
        }

        return null;
    }

    /**
     * Calculate the hard bounce rate for the last 24 hours
     */
    public function calculateBounceRate(int $workspaceId): float
    {
        $yesterday = Carbon::now()->subDay();

        $totalSent = Message::whereHas('source', function ($query) use ($workspaceId) {
                $query->where('workspace_id', $workspaceId);
            })
            ->whereNotNull('sent_at')
            ->where('sent_at', '>=', $yesterday)
            ->count();

        if ($totalSent === 0) {
            return 0.0;
        }

        $hardBounces = Message::whereHas('source', function ($query) use ($workspaceId) {
                $query->where('workspace_id', $workspaceId);
            })
            ->whereNotNull('bounced_at')
            ->where('smtp_code', '>=', 500)
            ->where('bounced_at', '>=', $yesterday)
            ->count();

        return $hardBounces / $totalSent;
    }

    /**
     * Calculate the complaint rate for the last 24 hours
     */
    public function calculateComplaintRate(int $workspaceId): float
    {
        $yesterday = Carbon::now()->subDay();

        $totalSent = Message::whereHas('source', function ($query) use ($workspaceId) {
                $query->where('workspace_id', $workspaceId);
            })
            ->whereNotNull('sent_at')
            ->where('sent_at', '>=', $yesterday)
            ->count();

        if ($totalSent === 0) {
            return 0.0;
        }

        $complaints = Message::whereHas('source', function ($query) use ($workspaceId) {
                $query->where('workspace_id', $workspaceId);
            })
            ->whereNotNull('complained_at')
            ->where('complained_at', '>=', $yesterday)
            ->count();

        return $complaints / $totalSent;
    }

    /**
     * Get reputation summary for a workspace
     */
    public function getReputationSummary(int $workspaceId): array
    {
        return [
            'bounce_rate_24h' => $this->calculateBounceRate($workspaceId),
            'complaint_rate_24h' => $this->calculateComplaintRate($workspaceId),
            'should_halt' => $this->shouldHaltSending($workspaceId),
            'complaint_warning' => $this->getComplaintWarning($workspaceId),
            'max_bounce_rate' => self::MAX_HARD_BOUNCE_RATE,
            'max_complaint_rate' => self::MAX_COMPLAINT_RATE,
        ];
    }

    /**
     * Auto-unsubscribe hard bounced emails to protect sender reputation
     */
    public function autoUnsubscribeHardBounces(): int
    {
        $hardBouncedMessages = Message::whereNotNull('bounced_at')
            ->where('smtp_code', '>=', 500)
            ->whereNull('unsubscribed_at')
            ->limit(1000)
            ->get();

        $count = 0;
        foreach ($hardBouncedMessages as $message) {
            $subscriber = Subscriber::find($message->subscriber_id);
            
            if ($subscriber && !$subscriber->unsubscribed_at) {
                $subscriber->unsubscribed_at = Carbon::now();
                $subscriber->save();
                $count++;
            }
        }

        Log::info('Auto-unsubscribed hard bounced subscribers', ['count' => $count]);

        return $count;
    }

    /**
     * Mark inactive subscribers after threshold period
     */
    public function markInactiveSubscribers(int $workspaceId): int
    {
        $threshold = Carbon::now()->subDays(self::INACTIVITY_THRESHOLD_DAYS);

        $inactiveCount = Subscriber::where('workspace_id', $workspaceId)
            ->whereNull('unsubscribed_at')
            ->whereDoesntHave('messages', function ($query) use ($threshold) {
                $query->where(function ($q) use ($threshold) {
                    $q->whereNotNull('opened_at')
                      ->orWhereNotNull('clicked_at')
                      ->orWhereNotNull('sent_at');
                })
                ->where('created_at', '>=', $threshold);
            })
            ->update(['is_inactive' => true]);

        Log::info('Marked inactive subscribers', [
            'workspace_id' => $workspaceId,
            'count' => $inactiveCount,
        ]);

        return $inactiveCount;
    }
}
