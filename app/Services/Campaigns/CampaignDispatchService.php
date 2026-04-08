<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Pipelines\Campaigns\CreateMessages;
use Illuminate\Pipeline\Pipeline;
use Sendportal\Base\Models\Campaign;
use Sendportal\Base\Pipelines\Campaigns\CompleteCampaign;
use Sendportal\Base\Pipelines\Campaigns\StartCampaign;

class CampaignDispatchService extends \Sendportal\Base\Services\Campaigns\CampaignDispatchService
{
    /**
     * Dispatch the campaign through a list-aware message creation pipeline.
     */
    public function handle(Campaign $campaign)
    {
        if (! $campaign = $this->findCampaign($campaign->id)) {
            return;
        }

        if (! $campaign->queued) {
            \Log::error('Campaign does not have a queued status campaign_id=' . $campaign->id . ' status_id=' . $campaign->status_id);

            return;
        }

        $pipes = [
            StartCampaign::class,
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
        } catch (\Exception $exception) {
            \Log::error('Error dispatching campaign id=' . $campaign->id . ' exception=' . $exception->getMessage() . ' trace=' . $exception->getTraceAsString());
        }
    }
}