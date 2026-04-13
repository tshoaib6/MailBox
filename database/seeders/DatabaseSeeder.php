<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed primary workspace owner first so workspace 1 exists before admin@eraxon.com is set up.
        $this->seedAdminUser('admin@sendportal.local', 'Shoaib Admin', 'password', 'My Workspace');

        // Seed secondary admin and grant access to the primary workspace so they can see all data.
        $this->seedAdminUser('admin@eraxon.com', 'Admin', 'qwerty', 'Default Workspace');
        $this->grantAccessToPrimaryWorkspace('admin@eraxon.com', 'admin@sendportal.local');
    }

    private function seedAdminUser(string $email, string $name, string $password, string $workspaceName): void
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'email_verified_at' => now(),
                'password' => Hash::make($password),
                'locale' => 'en',
            ]
        );

        $workspace = Workspace::firstOrCreate(
            ['owner_id' => $user->id],
            ['name' => $workspaceName]
        );

        $workspace->users()->syncWithoutDetaching([
            $user->id => ['role' => Workspace::ROLE_OWNER],
        ]);

        if ($user->current_workspace_id !== $workspace->id) {
            $user->forceFill(['current_workspace_id' => $workspace->id])->save();
        }
    }

    /**
     * Grant a secondary admin access to the primary owner's workspace and switch them to it,
     * so they can see all contact lists and subscribers imported by the primary admin.
     */
    private function grantAccessToPrimaryWorkspace(string $secondaryEmail, string $primaryEmail): void
    {
        $secondary = User::where('email', $secondaryEmail)->first();
        $primary   = User::where('email', $primaryEmail)->first();

        if (! $secondary || ! $primary) {
            return;
        }

        $primaryWorkspace = Workspace::where('owner_id', $primary->id)->first();

        if (! $primaryWorkspace) {
            return;
        }

        // Add the secondary admin as a member of the primary workspace.
        $primaryWorkspace->users()->syncWithoutDetaching([
            $secondary->id => ['role' => Workspace::ROLE_OWNER],
        ]);

        // Switch the secondary admin's active workspace to the primary workspace
        // so they see the contact lists and subscribers on first login.
        $secondary->forceFill(['current_workspace_id' => $primaryWorkspace->id])->save();
    }
}
