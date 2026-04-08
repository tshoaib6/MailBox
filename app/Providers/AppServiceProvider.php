<?php

declare(strict_types=1);

namespace App\Providers;

use App\LiveWire\Setup;
use App\Models\ApiToken;
use App\Models\ContactList;
use App\Models\User;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use RuntimeException;
use Sendportal\Base\Models\Campaign;
use Sendportal\Base\Facades\Sendportal;
use Sendportal\Base\Models\Subscriber;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Override MergeContentService to support contact list column mappings
        $this->app->singleton(
            \Sendportal\Base\Services\Content\MergeContentService::class,
            \App\Services\Content\MergeContentService::class
        );

        $this->app->singleton(
            \Sendportal\Base\Services\Campaigns\CampaignDispatchService::class,
            \App\Services\Campaigns\CampaignDispatchService::class
        );

        // For SMTP, set delivered_at = sent_at immediately (no delivery webhooks available).
        $this->app->bind(
            \Sendportal\Base\Services\Messages\MarkAsSent::class,
            \App\Services\Messages\MarkAsSent::class
        );

        $this->app->bind(
            \Sendportal\Base\Http\Controllers\Subscribers\SubscribersImportController::class,
            \App\Http\Controllers\Subscribers\SubscribersImportController::class
        );
    }

    public function boot(): void
    {
        Paginator::useBootstrap();

        Sendportal::setCurrentWorkspaceIdResolver(
            static function () {
                /** @var User $user */
                $user = auth()->user();
                $request = request();
                $workspaceId = null;

                if ($user) {
                    if (! empty($user->current_workspace_id)) {
                        $workspaceId = (int) $user->current_workspace_id;
                    } else {
                        $workspaceId = (int) DB::table('workspace_users')
                            ->where('user_id', $user->id)
                            ->orderBy('workspace_id')
                            ->value('workspace_id');

                        if ($workspaceId) {
                            DB::table('users')
                                ->where('id', $user->id)
                                ->update([
                                    'current_workspace_id' => $workspaceId,
                                    'updated_at' => now(),
                                ]);

                            $user->current_workspace_id = $workspaceId;
                        }
                    }
                } elseif ($request && (($apiToken = $request->bearerToken()) || ($apiToken = $request->get('api_token')))) {
                    $workspaceId = ApiToken::resolveWorkspaceId($apiToken);
                }

                if (! $workspaceId) {
                    throw new RuntimeException('Current Workspace ID Resolver must not return a null value.');
                }

                return $workspaceId;
            }
        );

        Sendportal::setSidebarHtmlContentResolver(
            static function () {
                try {
                    return view('layouts.sidebar.manageUsersMenuItem')->render();
                } catch (\Throwable $e) {
                    \Log::warning('Unable to render custom sidebar content: ' . $e->getMessage());

                    return '';
                }
            }
        );

        Sendportal::setHeaderHtmlContentResolver(
            static function () {
                try {
                    return view('layouts.header.userManagementHeader')->render();
                } catch (\Throwable $e) {
                    \Log::warning('Unable to render custom header content: ' . $e->getMessage());

                    return '';
                }
            }
        );

        Livewire::component('setup', Setup::class);

        Campaign::saved(function (Campaign $campaign) {
            try {
                if (app()->runningInConsole()) {
                    return;
                }

                $request = request();
                if (! $request) {
                    return;
                }

                if (!in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
                    return;
                }

                if (! $request->is('campaigns') && ! $request->is('campaigns/*')) {
                    return;
                }

                if (! $request->exists('contact_list_id')) {
                    return;
                }

                $contactListId = $request->filled('contact_list_id') ? $request->integer('contact_list_id') : null;

                DB::table('sendportal_campaigns')
                    ->where('id', $campaign->id)
                    ->update([
                        'contact_list_id' => $contactListId,
                        'updated_at' => now(),
                    ]);
            } catch (\Throwable $e) {
                // Ignore persistence fallback failures.
            }
        });

        // Inject subscriber list into the campaign preview view
        View::composer('sendportal::campaigns.preview', function ($view) {
            try {
                $workspaceId = Sendportal::currentWorkspaceId();
                $campaign = $view->getData()['campaign'] ?? null;

                $subscribersQuery = Subscriber::where('workspace_id', $workspaceId)
                    ->whereNull('unsubscribed_at')
                    ->orderBy('email');

                $subscriberCountQuery = DB::table('sendportal_subscribers')
                    ->where('workspace_id', $workspaceId)
                    ->whereNull('unsubscribed_at');

                if ($campaign && !empty($campaign->contact_list_id)) {
                    $subscribersQuery->where('contact_list_id', $campaign->contact_list_id);
                    $subscriberCountQuery->where('contact_list_id', $campaign->contact_list_id);
                }

                $subscribers = $subscribersQuery->get(['id', 'first_name', 'last_name', 'email']);
                $subscriberCount = (int) $subscriberCountQuery->count();
            } catch (\Throwable $e) {
                $subscribers = collect();
                $subscriberCount = 0;
            }
            $view->with('subscribers', $subscribers);
            $view->with('subscriberCount', $subscriberCount);
        });

        // Inject contact lists into the campaign form
        View::composer('sendportal::campaigns.partials.form', function ($view) {
            try {
                $workspaceId = Sendportal::currentWorkspaceId();
                $contactLists = ContactList::where('workspace_id', $workspaceId)
                    ->orderBy('name')
                    ->get(['id', 'name']);
            } catch (\Throwable $e) {
                $contactLists = collect();
            }
            $view->with('contactLists', $contactLists);
        });
    }
}
