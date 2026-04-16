<?php

use App\Http\Controllers\Auth\ApiTokenController;
use App\Http\Controllers\Api\ContactListMappingsController;
use App\Http\Controllers\Campaigns\CampaignContactPreviewController;
use App\Http\Controllers\Campaigns\CampaignsController;
use App\Http\Controllers\Campaigns\CampaignDispatchController;
use App\Http\Controllers\Campaigns\CampaignTestController;
use Sendportal\Base\Http\Controllers\Campaigns\CampaignsController as VendorCampaignsController;
use Sendportal\Base\Http\Controllers\Campaigns\CampaignDeleteController;
use Sendportal\Base\Http\Controllers\Campaigns\CampaignDuplicateController;
use Sendportal\Base\Http\Controllers\Campaigns\CampaignCancellationController;
use App\Http\Controllers\Tracking\EmailOpenTrackingController;
use App\Http\Controllers\Subscribers\ContactListsController;
use App\Http\Controllers\Subscribers\SubscribersImportController;
use App\Http\Controllers\Subscribers\SubscribersController;
use App\Http\Middleware\OwnsCurrentWorkspace;
use App\Http\Middleware\RequireWorkspace;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Sendportal\Base\Facades\Sendportal;

Auth::routes(
    [
        'verify' => config('sendportal-host.auth.register', false),
        'register' => config('sendportal-host.auth.register', false),
        'reset' => config('sendportal-host.auth.password_reset'),
    ]
);

Route::get('setup', 'SetupController@index')->name('setup');

// Auth.
Route::middleware('auth')->namespace('Auth')->group(
    static function (Router $authRouter) {
        // Profile.
        $authRouter->middleware('verified')->name('profile.')->prefix('profile')->group(
            static function (
                Router $profileRouter
            ) {
                $profileRouter->get('/', 'ProfileController@show')->name('show');
                $profileRouter->get('/edit', 'ProfileController@edit')->name('edit');
                $profileRouter->put('/', 'ProfileController@update')->name('update');

                // Password
                $profileRouter->name('password.')->prefix('password')->group(
                    static function (
                        Router $passwordRouter
                    ) {
                        $passwordRouter->get('/edit', 'ChangePasswordController@edit')->name('edit');
                        $passwordRouter->put('/', 'ChangePasswordController@update')->name('update');
                    }
                );
            }
        );

        // API Tokens.
        $authRouter->middleware('verified')->name('api-tokens.')->prefix('api-tokens')->group(static function (Router $apiTokenRouter) {
            $apiTokenRouter->get('/', [ApiTokenController::class, 'index'])->name('index');
            $apiTokenRouter->post('/', [ApiTokenController::class, 'store'])->name('store');
            $apiTokenRouter->delete('{tokenid}', [ApiTokenController::class, 'destroy'])->name('destroy');
        });
    }
);

// Workspace User Management.
Route::namespace('Workspaces')
    ->middleware(['auth', 'verified', RequireWorkspace::class, OwnsCurrentWorkspace::class])
    ->name('users.')
    ->prefix('users')
    ->group(
        static function (Router $workspacesRouter) {
            $workspacesRouter->get('/', 'WorkspaceUsersController@index')->name('index');
            $workspacesRouter->delete('{userId}', 'WorkspaceUsersController@destroy')->name('destroy');

            // Invitations.
            $workspacesRouter->name('invitations.')->prefix('invitations')
                ->group(
                    static function (Router $invitationsRouter) {
                        $invitationsRouter->post('/', 'WorkspaceInvitationsController@store')->name('store');
                        $invitationsRouter->delete('{invitation}', 'WorkspaceInvitationsController@destroy')
                            ->name('destroy');
                    }
                );
        }
    );

// Workspace Management.
Route::namespace('Workspaces')->middleware(
    [
        'auth',
        'verified',
        RequireWorkspace::class,
    ]
)->group(
    static function (Router $workspaceRouter) {
        $workspaceRouter->resource('workspaces', 'WorkspacesController')->except(
            [
                'create',
                'show',
                'destroy',
            ]
        );

        // Workspace Switching.
        $workspaceRouter->get('workspaces/{workspace}/switch', 'SwitchWorkspaceController@switch')
            ->name('workspaces.switch');

        // Invitations.
        $workspaceRouter->post('workspaces/invitations/{invitation}/accept', 'PendingInvitationController@accept')
            ->name('workspaces.invitations.accept');
        $workspaceRouter->post('workspaces/invitations/{invitation}/reject', 'PendingInvitationController@reject')
            ->name('workspaces.invitations.reject');
    }
);

Route::middleware(['auth', 'verified', RequireWorkspace::class])->group(
    static function () {
        // ── Our overrides (registered first so they take precedence for request handling) ──
        // These routes intentionally have no ->name() to avoid conflicts with the vendor's
        // named routes registered by Sendportal::webRoutes() below.

        // Subscriber overrides — URL-only, no name (vendor names still used by route() helper)
        Route::get('subscribers/import', [SubscribersImportController::class, 'show']);
        Route::post('subscribers/import', [SubscribersImportController::class, 'store']);
        Route::get('subscribers', [SubscribersController::class, 'index']);

        // Campaign literal routes — must come BEFORE {id} catch-alls so they are never swallowed.
        Route::get('campaigns/create', [VendorCampaignsController::class, 'create'])->name('sendportal.campaigns.create');
        Route::get('campaigns/sent', [VendorCampaignsController::class, 'sent'])->name('sendportal.campaigns.sent');
        Route::get('campaigns/{id}/confirm-delete', [CampaignDeleteController::class, 'confirm'])->name('sendportal.campaigns.destroy.confirm');
        Route::delete('campaigns', [CampaignDeleteController::class, 'destroy'])->name('sendportal.campaigns.destroy');
        Route::get('campaigns/{id}/duplicate', [CampaignDuplicateController::class, 'duplicate'])->name('sendportal.campaigns.duplicate');
        Route::get('campaigns/{id}/confirm-cancel', [CampaignCancellationController::class, 'confirm'])->name('sendportal.campaigns.confirm-cancel');
        Route::post('campaigns/{id}/cancel', [CampaignCancellationController::class, 'cancel'])->name('sendportal.campaigns.cancel');

        // Campaign overrides — URL-only, no name (vendor names still used by route() helper)
        Route::post('campaigns', [CampaignsController::class, 'store']);
        Route::get('campaigns/{id}/download-not-sent', [CampaignsController::class, 'downloadNotSent'])
            ->name('sendportal.campaigns.download-not-sent');
        Route::post('campaigns/{id}/dispatch-now', [CampaignsController::class, 'dispatchNow'])
            ->name('sendportal.campaigns.dispatch-now');
        Route::post('campaigns/{id}/force-send', [CampaignsController::class, 'forceSendNow'])
            ->name('sendportal.campaigns.force-send');
        Route::get('campaigns/{id}/contact-preview', [CampaignContactPreviewController::class, 'show'])
            ->name('sendportal.campaigns.contact-preview');
        Route::get('campaigns/{id}/preview', [CampaignsController::class, 'preview']);
        Route::post('campaigns/{id}/test', [CampaignTestController::class, 'handle']);
        Route::put('campaigns/{id}/send', [CampaignDispatchController::class, 'send']);
        Route::put('campaigns/{id}', [CampaignsController::class, 'update']);
        Route::get('campaigns/{id}', [CampaignsController::class, 'show']);

        // Contact Lists Management
        Route::resource('contact-lists', ContactListsController::class);

        // API endpoints
        Route::get('api/contact-lists/{id}/mappings', [ContactListMappingsController::class, 'show'])
            ->name('api.contact-list-mappings');

        // ── Vendor routes (provides all named routes for URL generation) ───────────────
        Sendportal::webRoutes();
    }
);

Route::get('track/open/{messageHash}.gif', [EmailOpenTrackingController::class, 'track'])
    ->name('tracking.email-open');

Sendportal::publicWebRoutes();
