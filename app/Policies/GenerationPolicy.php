<?php

namespace App\Policies;

use App\Models\Generation;
use App\Models\User;

class GenerationPolicy
{
    /**
     * Only its owner. A generation whose account was deleted (no owner) is visible to nobody.
     */
    public function view(User $user, Generation $generation): bool
    {
        return $generation->user_id === $user->id;
    }
}
