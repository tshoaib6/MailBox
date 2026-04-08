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
        $this->seedAdminUser('admin@eraxon.com', 'Admin', 'qwerty', 'Default Workspace');
        $this->seedAdminUser('admin@sendportal.local', 'Shoaib Admin', 'password', 'My Workspace');
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
}
