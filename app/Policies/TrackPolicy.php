<?php

namespace App\Policies;

use App\Models\Release;
use App\Models\Track;
use App\Models\User;

class TrackPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('tracks.view');
    }

    public function view(User $user, Track $track): bool
    {
        return $user->can('tracks.view');
    }

    public function create(User $user, Release $release): bool
    {
        return $user->can('tracks.create') && $release->isEditable();
    }

    public function update(User $user, Track $track): bool
    {
        return $user->can('tracks.update') && $track->release !== null && $track->release->isEditable();
    }

    public function delete(User $user, Track $track): bool
    {
        return $user->can('tracks.delete') && $track->release !== null && $track->release->isEditable();
    }

    /** Streaming or downloading the master audio. */
    public function downloadMaster(User $user, Track $track): bool
    {
        return $user->can('tracks.download_master');
    }
}
