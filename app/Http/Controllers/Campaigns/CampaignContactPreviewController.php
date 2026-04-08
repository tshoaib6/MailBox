<?php

declare(strict_types=1);

namespace App\Http\Controllers\Campaigns;

use App\Http\Controllers\Controller;
use App\Models\ContactList;
use Illuminate\Http\Request;
use Sendportal\Base\Facades\Sendportal;
use Sendportal\Base\Models\Subscriber;
use Sendportal\Base\Repositories\Campaigns\CampaignTenantRepositoryInterface;

class CampaignContactPreviewController extends Controller
{
    /** @var CampaignTenantRepositoryInterface */
    protected $campaigns;

    public function __construct(CampaignTenantRepositoryInterface $campaigns)
    {
        $this->campaigns = $campaigns;
    }

    /**
     * Return the campaign HTML with variables substituted for a specific subscriber.
     * GET /campaigns/{id}/contact-preview?subscriber_id=X
     */
    public function show(Request $request, int $campaignId)
    {
        $workspaceId = Sendportal::currentWorkspaceId();

        $campaign = $this->campaigns->find($workspaceId, $campaignId, ['template']);

        if (! $campaign) {
            abort(404);
        }

        // Build base content (merge template + campaign content)
        $content = $campaign->merged_content ?? '';

        // If a specific subscriber is requested, substitute their variables
        $subscriberId = $request->integer('subscriber_id');

        if ($subscriberId) {
            $subscriberQuery = Subscriber::where('workspace_id', $workspaceId);

            if (!empty($campaign->contact_list_id)) {
                $subscriberQuery->where('contact_list_id', $campaign->contact_list_id);
            }

            $subscriber = $subscriberQuery->findOrFail($subscriberId);

            $content = $this->substituteVariables($content, $subscriber, $campaign);
        } else {
            // Show placeholder values so the user understands where variables appear
            $content = $this->substitutePlaceholders($content);
        }

        return response($content)->header('Content-Type', 'text/html');
    }

    /**
     * Substitute real subscriber data into the content.
     * Uses contact list column mappings if available.
     */
    protected function substituteVariables(string $content, Subscriber $subscriber, $campaign): string
    {
        $tags = [
            'first_name'     => $subscriber->first_name ?? '',
            'last_name'      => $subscriber->last_name ?? '',
            'email'          => $subscriber->email ?? '',
            'unsubscribe_url' => '#unsubscribe',
            'webview_url'    => '#webview',
        ];

        // If the campaign has a contact list with mappings, use those
        if ($campaign->contact_list_id) {
            $tags = $this->getMappedTags($subscriber, $campaign, $tags);
        }

        // Replace both {{var}} and {{ var }} variants
        foreach ($tags as $key => $value) {
            $content = str_ireplace('{{' . $key . '}}', $value, $content);
            $content = str_ireplace('{{ ' . $key . ' }}', $value, $content);
        }

        return $content;
    }

    /**
     * Get tags using contact list column mappings.
     */
    private function getMappedTags(Subscriber $subscriber, $campaign, array $defaultTags): array
    {
        try {
            $contactList = ContactList::find($campaign->contact_list_id);
            if (!$contactList) {
                return $defaultTags;
            }

            // Get subscriber's full meta data (raw CSV row)
            $subscriberMeta = $subscriber->meta ?? [];
            if (is_string($subscriberMeta)) {
                $subscriberMeta = json_decode($subscriberMeta, true) ?? [];
            }

            // Build tags from mapped columns
            $tags = $defaultTags;
            foreach ($contactList->mappings as $mapping) {
                $csvValue = $subscriberMeta[$mapping->csv_column] ?? null;
                if ($csvValue !== null) {
                    $value = $this->stringifyValue($csvValue);
                    $tags[$mapping->merge_variable] = $value;
                    $tags[trim((string) $mapping->csv_column)] = $value;
                    $tags[$this->normalizeVariable((string) $mapping->csv_column)] = $value;
                }
            }

            return $tags;
        } catch (\Exception $e) {
            return $defaultTags;
        }
    }

    /**
     * Normalize header text into a variable key like serial_number.
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

    /**
     * Substitute visible placeholder text so raw tags are explained in the preview.
     */
    protected function substitutePlaceholders(string $content): string
    {
        $placeholders = [
            '{{first_name}}'       => '[First Name]',
            '{{last_name}}'        => '[Last Name]',
            '{{email}}'            => '[Email]',
            '{{unsubscribe_url}}'  => '#unsubscribe',
            '{{webview_url}}'      => '#webview',
            '{{ first_name }}'     => '[First Name]',
            '{{ last_name }}'      => '[Last Name]',
            '{{ email }}'          => '[Email]',
            '{{ unsubscribe_url }}' => '#unsubscribe',
            '{{ webview_url }}'    => '#webview',
        ];

        return str_replace(array_keys($placeholders), array_values($placeholders), $content);
    }
}

