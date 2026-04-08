<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Invitation;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

trait HasWorkspaces
{
    /** @var Workspace */
    protected $activeWorkspace;

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_users')
            ->orderBy('name', 'asc')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function hasWorkspaces(): bool
    {
        if (! $this->exists) {
            return false;
        }

        if ($this->relationLoaded('workspaces')) {
            $workspaces = $this->getRelation('workspaces');

            return ($workspaces?->count() ?? 0) > 0;
        }

        return $this->workspaces()->exists();
    }

    public function onWorkspace(?Workspace $workspace): bool
    {
        if (! $workspace || ! $this->exists) {
            return false;
        }

        if ($this->relationLoaded('workspaces')) {
            $workspaces = $this->getRelation('workspaces');

            return $workspaces ? $workspaces->contains($workspace) : false;
        }

        return $this->workspaces()->whereKey($workspace->id)->exists();
    }

    public function ownsWorkspace(Workspace $workspace): bool
    {
        return $this->id && $workspace->owner_id && (int) $this->id === (int) $workspace->owner_id;
    }

    public function currentWorkspaceId(): ?int
    {
        if ($this->activeWorkspace !== null) {
            return $this->activeWorkspace->id;
        }

        if ($this->current_workspace_id) {
            $workspace = Workspace::find($this->current_workspace_id);

            if ($workspace && $this->onWorkspace($workspace)) {
                $this->switchToWorkspace($workspace);

                return $this->activeWorkspace->id;
            }
        }

        if ($this->activeWorkspace === null && $this->hasWorkspaces()) {
            $workspace = $this->workspaces()->first();

            if ($workspace) {
                $this->switchToWorkspace($workspace);

                return $this->activeWorkspace->id;
            }
        }

        return null;
    }

    public function getCurrentWorkspaceAttribute(): ?Workspace
    {
        return $this->currentWorkspace();
    }

    public function ownsCurrentWorkspace(): bool
    {
        return $this->currentWorkspace() && (int) $this->currentWorkspace()->owner_id === (int) $this->id;
    }

    public function switchToWorkspace(Workspace $workspace): void
    {
        if (! $this->onWorkspace($workspace)) {
            throw new InvalidArgumentException('User does not belong to this workspace');
        }

        $this->activeWorkspace = $workspace;

        $this->current_workspace_id = $workspace->id;
        $this->save();
    }

    public function currentWorkspace(): ?Workspace
    {
        if ($this->activeWorkspace !== null) {
            return $this->activeWorkspace;
        }

        if ($this->current_workspace_id) {
            $workspace = Workspace::find($this->current_workspace_id);

            if ($workspace && $this->onWorkspace($workspace)) {
                $this->switchToWorkspace($workspace);

                return $this->activeWorkspace;
            }
        }

        if ($this->activeWorkspace === null && $this->hasWorkspaces()) {
            $workspace = $this->workspaces()->first();

            if ($workspace) {
                $this->switchToWorkspace($workspace);

                return $this->activeWorkspace;
            }
        }

        return null;
    }
}
