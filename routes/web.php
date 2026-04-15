<?php

use App\Http\Controllers\Auth\ApiTokenController;
use App\Http\Controllers\Api\ContactListMappingsController;
use App\Http\Controllers\Campaigns\CampaignContactPreviewController;
use App\Http\Controllers\Campaigns\CampaignsController;
use App\Http\Controllers\Campaigns\CampaignDispatchController;
use App\Http\Controllers\Campaigns\CampaignTestController;
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
        Sendportal::webRoutes();

        // Override vendor import routes (registered after so they take precedence).
        // Fixes: vendor hardcodes .csv extension, breaking Excel (.xlsx) uploads.
        Route::get('subscribers/import', [SubscribersImportController::class, 'show'])
            ->name('sendportal.subscribers.import');
        Route::post('subscribers/import', [SubscribersImportController::class, 'store'])
            ->name('sendportal.subscribers.import.store');

        // Contact Lists Management
        Route::resource('contact-lists', ContactListsController::class);
        // Override subscribers index to show contact lists instead of individual subscribers.
        Route::get('subscribers', [SubscribersController::class, 'index'])
            ->name('sendportal.subscribers.index');

        // Campaign contact-preview endpoint (returns rendered HTML with real subscriber data).
        Route::post('campaigns', [CampaignsController::class, 'store'])
            ->name('sendportal.campaigns.store');
        Route::get('campaigns/{id}', [CampaignsController::class, 'show'])
            ->name('sendportal.campaigns.show');
        Route::get('campaigns/{id}/download-not-sent', [CampaignsController::class, 'downloadNotSent'])
            ->name('sendportal.campaigns.download-not-sent');
        Route::post('campaigns/{id}/dispatch-now', [CampaignsController::class, 'dispatchNow'])
            ->name('sendportal.campaigns.dispatch-now');
        Route::put('campaigns/{id}', [CampaignsController::class, 'update'])
            ->name('sendportal.campaigns.update');
        Route::get('campaigns/{id}/preview', [CampaignsController::class, 'preview'])
            ->name('sendportal.campaigns.preview');

        // Campaign contact-preview endpoint (returns rendered HTML with real subscriber data).
        Route::get('campaigns/{id}/contact-preview', [CampaignContactPreviewController::class, 'show'])
            ->name('sendportal.campaigns.contact-preview');

        // Override campaign test route to support subscriber variable substitution.
        Route::post('campaigns/{id}/test', [CampaignTestController::class, 'handle'])
            ->name('sendportal.campaigns.test');

        // Override campaign send route to scope recipients to the selected contact list.
        Route::put('campaigns/{id}/send', [CampaignDispatchController::class, 'send'])
            ->name('sendportal.campaigns.send');

        // API endpoints
        Route::get('api/contact-lists/{id}/mappings', [ContactListMappingsController::class, 'show'])
            ->name('api.contact-list-mappings');
    }
);

Route::get('track/open/{messageHash}.gif', [EmailOpenTrackingController::class, 'track'])
    ->name('tracking.email-open');

Sendportal::publicWebRoutes();
