<?php

declare(strict_types=1);

namespace App\Http\Controllers\Campaigns;

use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Sendportal\Base\Facades\Sendportal;
use Sendportal\Base\Models\Campaign;
use Sendportal\Base\Models\Message;
use Sendportal\Base\Models\Subscriber;
use Sendportal\Base\Repositories\Campaigns\CampaignTenantRepositoryInterface;
use Sendportal\Base\Services\Messages\DispatchTestMessage;
use Throwable;

class CampaignTestController extends Controller
{
    /** @var DispatchTestMessage */
    protected $dispatchTestMessage;

    /** @var CampaignTenantRepositoryInterface */
    protected $campaigns;

    public function __construct(DispatchTestMessage $dispatchTestMessage, CampaignTenantRepositoryInterface $campaigns)
    {
        $this->dispatchTestMessage = $dispatchTestMessage;
        $this->campaigns = $campaigns;
    }

    /**
     * @throws Exception
     */
    public function handle(Request $request, int $campaignId): RedirectResponse
    {
        $request->validate([
            'recipient_email' => ['required', 'email'],
        ]);

        $workspaceId = Sendportal::currentWorkspaceId();
        $recipientEmail = $request->get('recipient_email');
        $subscriberId = $request->integer('subscriber_id') ?: $request->integer('preview_subscriber_id');
        $campaign = $this->campaigns->find($workspaceId, $campaignId);

        // If a specific subscriber is selected, use their data for variable substitution
        if ($subscriberId) {
            $subscriberQuery = Subscriber::where('workspace_id', $workspaceId);

            if ($campaign && !empty($campaign->contact_list_id)) {
                $subscriberQuery->where('contact_list_id', $campaign->contact_list_id);
            }

            $subscriber = $subscriberQuery->find($subscriberId);
        }

        try {
            if (! empty($subscriber)) {
                $messageId = $this->dispatchWithSubscriber($workspaceId, $campaignId, $recipientEmail, $subscriber);
            } else {
                $messageId = $this->dispatchTestMessage->handle($workspaceId, $campaignId, $recipientEmail);
            }
        } catch (Throwable $exception) {
            Log::error(
                'Test email dispatch failed campaign=' . $campaignId
                . ' recipient=' . $recipientEmail
                . ' error=' . $exception->getMessage()
                . ' trace=' . $exception->getTraceAsString(),
                ['exception' => $exception]
            );

            return redirect()->route('sendportal.campaigns.preview', $campaignId)
                ->withInput()
                ->with(['error' => __('Test email failed: :message', ['message' => $exception->getMessage()])]);
        }

        if (! $messageId) {
            return redirect()->route('sendportal.campaigns.preview', $campaignId)
                ->withInput()
                ->with(['error' => __('Failed to dispatch test email.')]);
        }

        return redirect()->route('sendportal.campaigns.preview', $campaignId)
            ->withInput()
            ->with(['success' => __('The test email has been dispatched.')]);
    }

    /**
     * Send a test email using a specific subscriber's data for variable substitution.
     *
     * @throws Exception
     */
    protected function dispatchWithSubscriber(int $workspaceId, int $campaignId, string $recipientEmail, Subscriber $subscriber): ?string
    {
        /** @var Campaign $campaign */
        $campaign = $this->campaigns->find($workspaceId, $campaignId);

        if (! $campaign) {
            return null;
        }

        // Create an unsaved Message instance that looks like a real subscriber message
        // but sends to the test recipient address
        $message = new Message([
            'workspace_id' => $workspaceId,
            'source_type'  => Campaign::class,
            'source_id'    => $campaign->id,
            'recipient_email' => $recipientEmail,
            'subject'      => '[Test] ' . $campaign->subject,
            'from_name'    => $campaign->from_name,
            'from_email'   => $campaign->from_email,
            'hash'         => 'abc123',
        ]);

        // Inject subscriber so MergeContentService can fill {{first_name}} etc.
        $message->setRelation('subscriber', $subscriber);

        // Resolve email service and tracking options via reflection on DispatchTestMessage
        // (we call the protected helpers by extracting the same flow inline)
        $dispatchRef = new \ReflectionClass($this->dispatchTestMessage);

        $getMergedContent = $dispatchRef->getMethod('getMergedContent');
        $getMergedContent->setAccessible(true);
        $mergedContent = $getMergedContent->invoke($this->dispatchTestMessage, $message);

        $getEmailService = $dispatchRef->getMethod('getEmailService');
        $getEmailService->setAccessible(true);
        $emailService = $getEmailService->invoke($this->dispatchTestMessage, $message);

        $trackingOptionsClass = \Sendportal\Base\Services\Messages\MessageTrackingOptions::class;
        $trackingOptions = $trackingOptionsClass::fromCampaign($campaign);

        $dispatch = $dispatchRef->getMethod('dispatch');
        $dispatch->setAccessible(true);

        return $dispatch->invoke($this->dispatchTestMessage, $message, $emailService, $trackingOptions, $mergedContent);
    }
}
