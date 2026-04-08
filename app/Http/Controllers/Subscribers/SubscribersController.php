<?php

namespace App\Http\Controllers\Subscribers;

use Illuminate\View\View;
use Sendportal\Base\Facades\Sendportal;
use Sendportal\Base\Http\Controllers\Subscribers\SubscribersController as VendorSubscribersController;
use App\Models\ContactList;

class SubscribersController extends VendorSubscribersController
{
    /**
     * Override index to show contact lists instead of individual subscribers
     */
    public function index(): View
    {
        $workspaceId = Sendportal::currentWorkspaceId();

        $contactLists = ContactList::withCount('subscribers')
            ->where('workspace_id', $workspaceId)
            ->orderBy('created_at', 'desc')
            ->get();

        return view('sendportal::subscribers.index', [
            'contactLists' => $contactLists,
        ]);
    }
}
