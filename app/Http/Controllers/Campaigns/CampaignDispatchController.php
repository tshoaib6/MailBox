<?php

declare(strict_types=1);

namespace App\Http\Controllers\Campaigns;

use Carbon\Carbon;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Sendportal\Base\Facades\Sendportal;
use Sendportal\Base\Http\Controllers\Controller;
use Sendportal\Base\Http\Requests\CampaignDispatchRequest;
use Sendportal\Base\Interfaces\QuotaServiceInterface;
use Sendportal\Base\Models\CampaignStatus;
use Sendportal\Base\Repositories\Campaigns\CampaignTenantRepositoryInterface;
use Sendportal\Base\Services\Campaigns\CampaignDispatchService;

class CampaignDispatchController extends Controller
{
    /** @var CampaignTenantRepositoryInterface */
    protected $campaigns;

    /** @var QuotaServiceInterface */
    protected $quotaService;

    /** @var CampaignDispatchService */
    protected $dispatchService;

    public function __construct(
        CampaignTenantRepositoryInterface $campaigns,
        QuotaServiceInterface $quotaService,
        CampaignDispatchService $dispatchService
    ) {
        $this->campaigns = $campaigns;
        $this->quotaService = $quotaService;
        $this->dispatchService = $dispatchService;
    }

    /**
     * Dispatch the campaign using contact-list-aware recipient counting.
     *
     * @throws Exception
     */
    public function send(CampaignDispatchRequest $request, int $id): RedirectResponse
    {
        $campaign = $this->campaigns->find(Sendportal::currentWorkspaceId(), $id, ['email_service', 'messages', 'tags']);

        if ($campaign->status_id !== CampaignStatus::STATUS_DRAFT) {
            return redirect()->route('sendportal.campaigns.status', $id);
        }

        if (! $campaign->email_service_id) {
            return redirect()->route('sendportal.campaigns.edit', $id)
                ->withErrors(__('Please select an Email Service'));
        }

        $campaign->update([
            'send_to_all' => $request->get('recipients') === 'send_to_all',
        ]);

        $campaign->tags()->sync($request->get('tags'));

        if ($this->quotaService->exceedsQuota($campaign->email_service, $this->getRecipientCount($campaign))) {
            return redirect()->route('sendportal.campaigns.edit', $id)
                ->withErrors(__('The number of subscribers for this campaign exceeds your SES quota'));
        }

        $scheduledAt = $request->get('schedule') === 'scheduled' ? Carbon::parse($request->get('scheduled_at')) : now();

        $campaign->update([
            'scheduled_at' => $scheduledAt,
            'status_id' => CampaignStatus::STATUS_QUEUED,
            'save_as_draft' => $request->get('behaviour') === 'draft',
        ]);

        $this->spawnBackgroundDispatch();

        return redirect()->route('sendportal.campaigns.show', $id)
            ->with('success', __('Campaign queued successfully. Sending will begin in a few seconds — refresh this page to see progress.'));
    }

    /**
     * Spawn a detached CLI process to run sp:campaigns:dispatch.
     * PHP CLI has no max_execution_time, so large batches complete without timeout.
     */
    private function spawnBackgroundDispatch(): void
    {
        try {
            $php    = PHP_BINARY;
            $artisan = escapeshellarg(base_path('artisan'));
            $log    = escapeshellarg(storage_path('logs/dispatch-bg.log'));
            exec("nohup {$php} {$artisan} sp:campaigns:dispatch >> {$log} 2>&1 &");
        } catch (\Throwable $e) {
            \Log::error('Failed to spawn background dispatch process: ' . $e->getMessage());
        }
    }

    private function getRecipientCount($campaign): int
    {
        $query = DB::table('sendportal_subscribers')
            ->where('workspace_id', $campaign->workspace_id)
            ->whereNull('unsubscribed_at');

        if (!empty($campaign->contact_list_id)) {
            $query->where('contact_list_id', $campaign->contact_list_id);
        }

        if (!$campaign->send_to_all && $campaign->tags->count()) {
            $query->join('sendportal_tag_subscriber', 'sendportal_tag_subscriber.subscriber_id', '=', 'sendportal_subscribers.id')
                ->whereIn('sendportal_tag_subscriber.tag_id', $campaign->tags->pluck('id')->all())
                ->distinct('sendportal_subscribers.id');
        }

        return (int) $query->count('sendportal_subscribers.id');
    }
}