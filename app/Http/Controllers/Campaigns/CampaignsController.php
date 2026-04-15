<?php

declare(strict_types=1);

namespace App\Http\Controllers\Campaigns;

use Exception;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
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

        // Prevent a second dispatch if the campaign is already queued or actively sending.
        if ($campaign->queued || $campaign->sending) {
            return redirect()->route('sendportal.campaigns.show', $id)
                ->with('info', __('Campaign is already being dispatched. Please wait.'));
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

        $baseQuery = Message::where('workspace_id', $workspaceId)
            ->where('source_type', \Sendportal\Base\Models\Campaign::class)
            ->where('source_id', $campaign->id);

        $filter = request()->query('filter', 'all');

        $messagesQuery = (clone $baseQuery)->orderByDesc('id');

        if ($filter === 'sent') {
            $messagesQuery->whereNotNull('sent_at');
        } elseif ($filter === 'not_sent') {
            $messagesQuery->whereNull('sent_at');
        } elseif ($filter === 'failed') {
            $messagesQuery->where(function ($q) {
                $q->whereIn('send_status', ['rejected', 'error'])
                  ->orWhereNotNull('bounced_at');
            });
        }

        $messages = $messagesQuery->paginate(50)->withQueryString();

        $recipientStats = [
            'total'     => (clone $baseQuery)->count(),
            'sent'      => (clone $baseQuery)->whereNotNull('sent_at')->count(),
            'not_sent'  => (clone $baseQuery)->whereNull('sent_at')->count(),
            'delivered' => (clone $baseQuery)->where(function ($query) {
                $query->where('send_status', 'delivered')
                    ->orWhereNotNull('delivered_at');
            })->count(),
            'opened'    => (clone $baseQuery)->whereNotNull('opened_at')->count(),
            'clicked'   => (clone $baseQuery)->whereNotNull('clicked_at')->count(),
            'rejected'  => (clone $baseQuery)->where('send_status', 'rejected')->count(),
            'errors'    => (clone $baseQuery)->where('send_status', 'error')->count(),
            'failed'    => (clone $baseQuery)->where(function ($query) {
                $query->whereIn('send_status', ['rejected', 'error'])
                      ->orWhere(function ($smtpQuery) {
                          $smtpQuery->whereNotNull('smtp_code')
                              ->whereBetween('smtp_code', [400, 599]);
                      })
                      ->orWhereNotNull('bounced_at')
                      ->orWhereNotNull('complained_at');
            })->count(),
        ];

        return view('campaigns.show', compact('campaign', 'messages', 'recipientStats', 'filter'));
    }

    /**
     * Download a CSV of all not-sent recipients for a campaign.
     *
     * @throws Exception
     */
    public function downloadNotSent(int $id): Response
    {
        $workspaceId = Sendportal::currentWorkspaceId();
        $campaign = $this->campaigns->find($workspaceId, $id);

        $messages = Message::where('workspace_id', $workspaceId)
            ->where('source_type', \Sendportal\Base\Models\Campaign::class)
            ->where('source_id', $campaign->id)
            ->whereNull('sent_at')
            ->orderBy('recipient_email')
            ->get(['recipient_email', 'send_status', 'smtp_code', 'smtp_message', 'error_detail', 'attempted_at']);

        $rows   = [];
        $rows[] = implode(',', ['Email', 'Status', 'SMTP Code', 'Reason', 'Attempted At']);

        foreach ($messages as $msg) {
            $reason = $msg->smtp_message ?: $msg->error_detail ?: '';
            $rows[] = implode(',', [
                '"' . str_replace('"', '""', $msg->recipient_email) . '"',
                '"' . str_replace('"', '""', $msg->send_status ?? '') . '"',
                '"' . ($msg->smtp_code ?? '') . '"',
                '"' . str_replace('"', '""', $reason) . '"',
                '"' . ($msg->attempted_at ?? '') . '"',
            ]);
        }

        $filename = 'not-sent-' . \Str::slug($campaign->name) . '-' . now()->format('Ymd-His') . '.csv';

        return response(implode("\n", $rows), 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
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