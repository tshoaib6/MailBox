<?php

declare(strict_types=1);

namespace App\Http\Controllers\Campaigns;

use Exception;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Sendportal\Base\Facades\Sendportal;
use Sendportal\Base\Http\Controllers\Campaigns\CampaignsController as BaseCampaignsController;
use Sendportal\Base\Http\Requests\CampaignStoreRequest;
use Sendportal\Base\Models\CampaignStatus;
use Sendportal\Base\Models\Message;

class CampaignsController extends BaseCampaignsController
{
    /**
     * Manually dispatch a campaign immediately.
     *
     * @throws Exception
     */
    public function dispatchNow(int $id): RedirectResponse
    {
        $workspaceId = Sendportal::currentWorkspaceId();
        $campaign = $this->campaigns->find($workspaceId, $id, ['tags', 'status']);

        if (! $campaign) {
            return redirect()->route('sendportal.campaigns.index')
                ->with('error', __('Campaign not found.'));
        }

        if ($campaign->sent) {
            return redirect()->route('sendportal.campaigns.show', $id)
                ->with('success', __('Campaign has already been sent.'));
        }

        $campaign->update([
            'save_as_draft' => false,
            'scheduled_at' => now(),
            'status_id' => CampaignStatus::STATUS_QUEUED,
        ]);

        $this->spawnBackgroundDispatch();

        return redirect()->route('sendportal.campaigns.show', $id)
            ->with('success', __('Campaign queued. Sending will begin in a few seconds — refresh this page to see progress.'));
    }

    /**
     * Spawn a detached CLI process to run sp:campaigns:dispatch.
     * PHP CLI has no max_execution_time, so large batches complete without timeout.
     */
    private function spawnBackgroundDispatch(): void
    {
        try {
            $php     = PHP_BINARY;
            $artisan = escapeshellarg(base_path('artisan'));
            $log     = escapeshellarg(storage_path('logs/dispatch-bg.log'));
            exec("nohup {$php} {$artisan} sp:campaigns:dispatch >> {$log} 2>&1 &");
        } catch (\Throwable $e) {
            \Log::error('Failed to spawn background dispatch process: ' . $e->getMessage());
        }
    }

    /**
     * Campaign detail page with recipient-level sent status.
     *
     * @throws Exception
     */
    public function show(int $id): ViewContract
    {
        $workspaceId = Sendportal::currentWorkspaceId();
        $campaign = $this->campaigns->find($workspaceId, $id, ['status', 'template', 'email_service']);
        $campaign?->loadMissing('email_service.type');

        $messages = Message::where('workspace_id', $workspaceId)
            ->where('source_type', \Sendportal\Base\Models\Campaign::class)
            ->where('source_id', $campaign->id)
            ->orderByDesc('id')
            ->paginate(50);

        $baseQuery = Message::where('workspace_id', $workspaceId)
            ->where('source_type', \Sendportal\Base\Models\Campaign::class)
            ->where('source_id', $campaign->id);

        $recipientStats = [
            'total'     => (clone $baseQuery)->count(),
            'sent'      => (clone $baseQuery)->whereNotNull('sent_at')->count(),
            'delivered' => (clone $baseQuery)->whereNotNull('delivered_at')->count(),
            'opened'    => (clone $baseQuery)->whereNotNull('opened_at')->count(),
            'clicked'   => (clone $baseQuery)->whereNotNull('clicked_at')->count(),
            'failed'    => (clone $baseQuery)->where(function ($query) {
                $query->whereNotNull('bounced_at')
                      ->orWhereNotNull('complained_at');
            })->count(),
        ];

        return view('campaigns.show', compact('campaign', 'messages', 'recipientStats'));
    }

    /**
     * Persist contact_list_id along with the validated campaign payload.
     *
     * @throws Exception
     */
    public function store(CampaignStoreRequest $request): RedirectResponse
    {
        $workspaceId = Sendportal::currentWorkspaceId();

        $payload = array_merge(
            $this->handleCheckboxes($request->validated()),
            [
                'contact_list_id' => $request->filled('contact_list_id') ? $request->integer('contact_list_id') : null,
            ]
        );

        $campaign = $this->campaigns->store($workspaceId, $payload);

        return redirect()->route('sendportal.campaigns.preview', $campaign->id);
    }

    /**
     * Persist contact_list_id on campaign update.
     *
     * @throws Exception
     */
    public function update(int $campaignId, CampaignStoreRequest $request): RedirectResponse
    {
        $workspaceId = Sendportal::currentWorkspaceId();

        $payload = array_merge(
            $this->handleCheckboxes($request->validated()),
            [
                'contact_list_id' => $request->filled('contact_list_id') ? $request->integer('contact_list_id') : null,
            ]
        );

        $campaign = $this->campaigns->update($workspaceId, $campaignId, $payload);

        return redirect()->route('sendportal.campaigns.preview', $campaign->id);
    }

    /**
     * Preview with subscriber count scoped to the selected contact list.
     *
     * @return RedirectResponse|ViewContract
     * @throws Exception
     */
    public function preview(int $id)
    {
        $workspaceId = Sendportal::currentWorkspaceId();
        $campaign = $this->campaigns->find($workspaceId, $id);

        if (! $campaign->draft) {
            return redirect()->route('sendportal.campaigns.status', $id);
        }

        $subscriberCount = DB::table('sendportal_subscribers')
            ->where('workspace_id', $workspaceId)
            ->whereNull('unsubscribed_at')
            ->when(!empty($campaign->contact_list_id), function ($query) use ($campaign) {
                $query->where('contact_list_id', $campaign->contact_list_id);
            })
            ->count();

        $tags = $this->tags->all($workspaceId, 'name');

        return view('sendportal::campaigns.preview', compact('campaign', 'tags', 'subscriberCount'));
    }

    /**
     * Keep checkbox behavior aligned with vendor controller.
     */
    private function handleCheckboxes(array $input): array
    {
        $checkboxFields = [
            'is_open_tracking',
            'is_click_tracking',
        ];

        foreach ($checkboxFields as $checkboxField) {
            if (! isset($input[$checkboxField])) {
                $input[$checkboxField] = false;
            }
        }

        return $input;
    }
}