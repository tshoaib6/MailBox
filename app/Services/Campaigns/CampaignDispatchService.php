<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Pipelines\Campaigns\CreateMessages;
use App\Support\Campaigns\CampaignSendReportService;
use Carbon\CarbonImmutable;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\DB;
use Sendportal\Base\Models\Campaign;
use Sendportal\Base\Models\CampaignStatus;
use Sendportal\Base\Pipelines\Campaigns\CompleteCampaign;

class CampaignDispatchService extends \Sendportal\Base\Services\Campaigns\CampaignDispatchService
{
    /**
     * Dispatch the campaign through a list-aware message creation pipeline.
     *
     * Uses an atomic DB UPDATE to claim the campaign from QUEUED→SENDING before doing any work.
     * This guarantees that even if the scheduler and a manually-spawned background process both
     * run sp:campaigns:dispatch at the same time, only one will ever dispatch each campaign.
     */
    public function handle(Campaign $campaign)
    {
        // Atomically transition QUEUED → SENDING.
        // MySQL guarantees this UPDATE is atomic: exactly one process will get rowsAffected=1.
        // Any concurrent process will get rowsAffected=0 and bail out, preventing double-dispatch.
        $claimed = DB::table('sendportal_campaigns')
            ->where('id', $campaign->id)
            ->where('status_id', CampaignStatus::STATUS_QUEUED)
            ->update(['status_id' => CampaignStatus::STATUS_SENDING]);

        if (! $claimed) {
            \Log::info('Campaign already claimed by another process, skipping id=' . $campaign->id);
            return null;
        }

        $startedAt = CarbonImmutable::now('UTC');

        // Reload with fresh status and relations after the atomic claim.
        if (! $campaign = $this->findCampaign($campaign->id)) {
            return null;
        }

        // StartCampaign is intentionally excluded — we already transitioned to SENDING atomically above.
        $pipes = [
            CreateMessages::class,
            CompleteCampaign::class,
        ];

        try {
            app(Pipeline::class)
                ->send($campaign)
                ->through($pipes)
                ->then(function ($campaign) {
                    return $campaign;
                });

            if (config('queue.default') === 'sync') {
                $summary = app(CampaignSendReportService::class)->buildForCampaignRun($campaign, $startedAt);
                app(CampaignSendReportService::class)->emit($summary);

                return $summary;
            }
        } catch (\Exception $exception) {
            \Log::error('Error dispatching campaign id=' . $campaign->id . ' exception=' . $exception->getMessage() . ' trace=' . $exception->getTraceAsString());
        }

        return null;
    }
}