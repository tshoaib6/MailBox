<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\ContactList;
use Illuminate\Http\JsonResponse;
use Sendportal\Base\Facades\Sendportal;

class ContactListMappingsController
{
    public function show(int $id): JsonResponse
    {
        $workspaceId = Sendportal::currentWorkspaceId();

        $contactList = ContactList::where('workspace_id', $workspaceId)
            ->whereId($id)
            ->with('mappings')
            ->first();

        if (!$contactList) {
            return response()->json(['error' => 'Contact list not found'], 404);
        }

        return response()->json([
            'contactList' => $contactList,
            'mappings' => $contactList->mappings,
        ]);
    }
}
