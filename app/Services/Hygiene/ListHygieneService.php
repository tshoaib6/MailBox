<?php

declare(strict_types=1);

namespace App\Services\Hygiene;

use Illuminate\Support\Facades\Log;
use Sendportal\Base\Models\Subscriber;

/**
 * List Hygiene Service
 * 
 * Validates email addresses, blocks disposable domains,
 * and maintains clean subscriber lists.
 */
class ListHygieneService
{
    /**
     * Common disposable email domains to block
     */
    private const DISPOSABLE_DOMAINS = [
        'tempmail.com',
        'guerrillamail.com',
        'mailinator.com',
        '10minutemail.com',
        'throwaway.email',
        'temp-mail.org',
        'fakeinbox.com',
        'trashmail.com',
        'yopmail.com',
        'getnada.com',
        'maildrop.cc',
        'sharklasers.com',
        'guerrillamail.info',
        'grr.la',
        'guerrillamail.biz',
        'guerrillamail.de',
        'spam4.me',
        'mailnesia.com',
        'jetable.org',
        'mytemp.email',
        'mohmal.com',
        'emailondeck.com',
        'dispostable.com',
        'tempinbox.com',
    ];

    /**
     * Validate an email address
     */
    public function isValidEmail(string $email): bool
    {
        // Basic syntax validation
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Check for disposable domains
        $domain = strtolower(explode('@', $email)[1]);
        if ($this->isDisposableDomain($domain)) {
            return false;
        }

        return true;
    }

    /**
     * Check if a domain is disposable
     */
    public function isDisposableDomain(string $domain): bool
    {
        return in_array($domain, self::DISPOSABLE_DOMAINS, true);
    }

    /**
     * Get list of disposable domains
     */
    public function getDisposableDomains(): array
    {
        return self::DISPOSABLE_DOMAINS;
    }

    /**
     * Add a domain to the disposable list
     */
    public function addDisposableDomain(string $domain): void
    {
        $domain = strtolower($domain);
        
        if (!in_array($domain, self::DISPOSABLE_DOMAINS, true)) {
            Log::info('Added disposable domain', ['domain' => $domain]);
        }
    }

    /**
     * Clean invalid subscribers from a workspace
     */
    public function cleanInvalidSubscribers(int $workspaceId): int
    {
        $invalidCount = 0;
        
        Subscriber::where('workspace_id', $workspaceId)
            ->whereNull('unsubscribed_at')
            ->chunkById(1000, function ($subscribers) use (&$invalidCount) {
                foreach ($subscribers as $subscriber) {
                    if (!$this->isValidEmail($subscriber->email)) {
                        $subscriber->unsubscribed_at = now();
                        $subscriber->save();
                        $invalidCount++;
                        
                        Log::info('Unsubscribed invalid email', [
                            'subscriber_id' => $subscriber->id,
                            'email' => $subscriber->email,
                            'reason' => 'invalid_or_disposable',
                        ]);
                    }
                }
            });

        return $invalidCount;
    }

    /**
     * Validate and clean a batch of emails before import
     */
    public function validateEmailBatch(array $emails): array
    {
        $valid = [];
        $invalid = [];
        $disposable = [];

        foreach ($emails as $email) {
            if (!$this->isValidEmail($email)) {
                $domain = strtolower(explode('@', $email)[1]);
                
                if ($this->isDisposableDomain($domain)) {
                    $disposable[] = $email;
                } else {
                    $invalid[] = $email;
                }
            } else {
                $valid[] = $email;
            }
        }

        return [
            'valid' => $valid,
            'invalid' => $invalid,
            'disposable' => $disposable,
            'summary' => [
                'total' => count($emails),
                'valid_count' => count($valid),
                'invalid_count' => count($invalid),
                'disposable_count' => count($disposable),
            ],
        ];
    }

    /**
     * Get hygiene statistics for a workspace
     */
    public function getHygieneStats(int $workspaceId): array
    {
        $total = Subscriber::where('workspace_id', $workspaceId)->count();
        $unsubscribed = Subscriber::where('workspace_id', $workspaceId)
            ->whereNotNull('unsubscribed_at')->count();
        $active = $total - $unsubscribed;

        return [
            'total_subscribers' => $total,
            'active_subscribers' => $active,
            'unsubscribed' => $unsubscribed,
            'health_score' => $total > 0 ? round(($active / $total) * 100, 2) : 100,
        ];
    }
}
