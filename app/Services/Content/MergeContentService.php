<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContactList;
use Sendportal\Base\Models\Campaign;
use Sendportal\Base\Models\Message;
use Sendportal\Base\Services\Content\MergeContentService as BaseMergeContentService;

/**
 * Extension of the base MergeContentService that supports contact list column mappings.
 * When a campaign is associated with a contact list that has column mappings,
 * variables are substituted using the mapped column values from the subscriber's meta data.
 */
class MergeContentService extends BaseMergeContentService
{
    /**
     * Extend base tag merge by appending a 1x1 open-tracking pixel.
     */
    protected function mergeTags(string $content, Message $message): string
    {
        $content = parent::mergeTags($content, $message);

        if (! $this->shouldTrackOpens($message)) {
            return $content;
        }

        return $this->appendOpenPixel($content, $message);
    }

    /**
     * Override: use contact list mappings if available.
     */
    protected function mergeSubscriberTags(string $content, Message $message): string
    {
        $tags = [
            'email' => $message->recipient_email,
            'first_name' => optional($message->subscriber)->first_name ?? '',
            'last_name' => optional($message->subscriber)->last_name ?? ''
        ];

        // If the message is from a campaign with a contact list, use mapped column values
        if ($message->isCampaign() && $message->subscriber) {
            $tags = $this->getMappedTags($message, $tags);
        }

        foreach ($tags as $key => $replace) {
            $content = str_ireplace('{{' . $key . '}}', $replace, $content);
            $content = str_ireplace('{{ ' . $key . ' }}', $replace, $content);
        }

        return $content;
    }

    /**
     * Resolve tags from contact list column mappings.
     */
    private function getMappedTags(Message $message, array $defaultTags): array
    {
        try {
            // Get the campaign
            $campaign = $this->campaignRepo->find($message->workspace_id, $message->source_id);
            if (!$campaign || !$campaign->contact_list_id) {
                return $defaultTags;
            }

            // Get the contact list with mappings
            $contactList = ContactList::find($campaign->contact_list_id);
            if (!$contactList || !$message->subscriber) {
                return $defaultTags;
            }

            // Get subscriber's full meta data
            $subscriberMeta = $message->subscriber->meta ?? [];
            if (is_string($subscriberMeta)) {
                $subscriberMeta = json_decode($subscriberMeta, true) ?? [];
            }

            // Build tags from mapped columns
            $tags = $defaultTags;
            foreach ($contactList->mappings as $mapping) {
                $csvValue = $subscriberMeta[$mapping->csv_column] ?? null;
                if ($csvValue !== null) {
                    $value = $this->stringifyValue($csvValue);
                    if (! $this->hasFilledTag($tags, (string) $mapping->merge_variable)) {
                        $tags[$mapping->merge_variable] = $value;
                    }

                    // Support direct header-style tags too, e.g. {{Serial Number}}.
                    $headerTag = trim((string) $mapping->csv_column);
                    if ($headerTag !== '' && ! $this->hasFilledTag($tags, $headerTag)) {
                        $tags[$headerTag] = $value;
                    }

                    // Support normalized tag format, e.g. {{serial_number}}.
                    $normalizedTag = $this->normalizeVariable((string) $mapping->csv_column);
                    if ($normalizedTag !== '' && ! $this->hasFilledTag($tags, $normalizedTag)) {
                        $tags[$normalizedTag] = $value;
                    }
                }
            }

            return $tags;
        } catch (\Exception $e) {
            return $defaultTags;
        }
    }

    /**
     * Convert header text to canonical variable format.
     */
    private function normalizeVariable(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
        $value = strtolower(trim($value));
        $value = str_replace(['-', '.', '/'], ' ', $value);
        $value = preg_replace('/\s+/', '_', $value);
        $value = preg_replace('/[^a-z0-9_]/', '', $value);

        return trim((string) $value, '_');
    }

    /**
     * Convert scalar/array/object values to a readable string for replacement.
     */
    private function stringifyValue($value): string
    {
        if (is_array($value)) {
            if (array_key_exists('date', $value)) {
                return (string) $value['date'];
            }

            return implode(', ', array_map(static function ($item) {
                return is_scalar($item) ? (string) $item : json_encode($item);
            }, $value));
        }

        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : json_encode($value);
        }

        return (string) $value;
    }

    private function hasFilledTag(array $tags, string $key): bool
    {
        if (! array_key_exists($key, $tags)) {
            return false;
        }

        return trim((string) $tags[$key]) !== '';
    }

    private function shouldTrackOpens(Message $message): bool
    {
        if (! $message->isCampaign()) {
            return true;
        }

        $campaign = $this->campaignRepo->find($message->workspace_id, $message->source_id);

        if (! $campaign) {
            return false;
        }

        return (bool) ($campaign->is_open_tracking ?? true);
    }

    private function appendOpenPixel(string $content, Message $message): string
    {
        $pixelUrl = route('tracking.email-open', ['messageHash' => $message->hash]);
        $pixel = '<img src="' . e($pixelUrl) . '" alt="" width="1" height="1" style="display:none !important;width:1px !important;height:1px !important;border:0 !important;" />';

        if (stripos($content, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $pixel . '</body>', $content, 1) ?? ($content . $pixel);
        }

        return $content . $pixel;
    }
}
