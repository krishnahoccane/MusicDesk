<?php

namespace App\Policies;

use App\Models\Artist;
use App\Models\User;

class ArtistPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('artists.view');
    }

    public function view(User $user, Artist $artist): bool
    {
        return $user->can('artists.view');
    }

    public function create(User $user): bool
    {
        return $user->can('artists.create');
    }

    public function update(User $user, Artist $artist): bool
    {
        return $user->can('artists.update') && ! $artist->trashed();
    }

    public function delete(User $user, Artist $artist): bool
    {
        return $user->can('artists.delete') && ! $artist->trashed();
    }

    public function restore(User $user, Artist $artist): bool
    {
        return $user->can('artists.delete') && $artist->trashed();
    }

    public function manageContracts(User $user, Artist $artist): bool
    {
        return $user->can('contracts.manage') && ! $artist->trashed();
    }

    public function manageDocuments(User $user, Artist $artist): bool
    {
        return $user->can('documents.manage') && ! $artist->trashed();
    }
}
