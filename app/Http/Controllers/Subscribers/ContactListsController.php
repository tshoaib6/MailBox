<?php

declare(strict_types=1);

namespace App\Http\Controllers\Subscribers;

use App\Http\Controllers\Controller;
use App\Models\ContactList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Sendportal\Base\Facades\Sendportal;

class ContactListsController extends Controller
{
    /**
     * Show all contact lists for the workspace.
     */
    public function index(): View
    {
        $workspaceId = Sendportal::currentWorkspaceId();
        
        $contactLists = ContactList::where('workspace_id', $workspaceId)
            ->withCount('subscribers')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('contact-lists.index', compact('contactLists'));
    }

    /**
     * Show subscribers for a single contact list.
     */
    public function show(int $id): View|RedirectResponse
    {
        $workspaceId = Sendportal::currentWorkspaceId();

        $contactList = ContactList::where('workspace_id', $workspaceId)
            ->find($id);

        if (! $contactList) {
            return redirect()->route('contact-lists.index')
                ->with('error', __('Contact list not found in your current workspace.'));
        }

        $subscribers = DB::table('sendportal_subscribers')
            ->where('workspace_id', $workspaceId)
            ->where('contact_list_id', $contactList->id)
            ->select(['id', 'email', 'first_name', 'last_name', 'meta', 'created_at'])
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $subscribers->getCollection()->transform(static function ($subscriber) {
            $meta = [];

            if (isset($subscriber->meta)) {
                if (is_string($subscriber->meta) && $subscriber->meta !== '') {
                    $decoded = json_decode($subscriber->meta, true);
                    $meta = is_array($decoded) ? $decoded : [];
                } elseif (is_array($subscriber->meta)) {
                    $meta = $subscriber->meta;
                } elseif (is_object($subscriber->meta)) {
                    $meta = (array) $subscriber->meta;
                }
            }

            $subscriber->meta_array = $meta;

            return $subscriber;
        });

        return view('contact-lists.show', compact('contactList', 'subscribers'));
    }

    /**
     * Show the form to create a new contact list.
     */
    public function create(): View
    {
        return view('contact-lists.create');
    }

    /**
     * Store a new contact list.
     */
    public function store(\Illuminate\Http\Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $workspaceId = Sendportal::currentWorkspaceId();

        // Check if list with this name already exists
        $exists = ContactList::where('workspace_id', $workspaceId)
            ->where('name', $request->name)
            ->exists();

        if ($exists) {
            return redirect()->back()
                ->withInput()
                ->with('error', __('A contact list with this name already exists in your workspace.'));
        }

        ContactList::create([
            'workspace_id' => $workspaceId,
            'name' => $request->name,
            'columns' => [],
        ]);

        return redirect()->route('contact-lists.index')
            ->with('success', __('Contact list created successfully.'));
    }

    /**
     * Show the form to edit a contact list.
     */
    public function edit(int $id): View
    {
        $workspaceId = Sendportal::currentWorkspaceId();
        
        $contactList = ContactList::where('workspace_id', $workspaceId)
            ->findOrFail($id);

        $contactList->loadCount('subscribers');

        return view('contact-lists.edit', compact('contactList'));
    }

    /**
     * Update a contact list.
     */
    public function update(\Illuminate\Http\Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $workspaceId = Sendportal::currentWorkspaceId();

        $contactList = ContactList::where('workspace_id', $workspaceId)
            ->findOrFail($id);

        // Check if another list with this name exists
        $exists = ContactList::where('workspace_id', $workspaceId)
            ->where('name', $request->name)
            ->where('id', '<>', $id)
            ->exists();

        if ($exists) {
            return redirect()->back()
                ->withInput()
                ->with('error', __('A contact list with this name already exists in your workspace.'));
        }

        $contactList->update(['name' => $request->name]);

        return redirect()->route('contact-lists.index')
            ->with('success', __('Contact list updated successfully.'));
    }

    /**
     * Delete a contact list.
     */
    public function destroy(int $id): RedirectResponse
    {
        $workspaceId = Sendportal::currentWorkspaceId();

        $contactList = ContactList::where('workspace_id', $workspaceId)
            ->findOrFail($id);

        $name = $contactList->name;
        $contactList->delete();

        return redirect()->route('contact-lists.index')
            ->with('success', __('Contact list ":name" deleted successfully.', ['name' => $name]));
    }
}
