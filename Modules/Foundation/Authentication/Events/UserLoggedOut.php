<?php

namespace Modules\Foundation\Authentication\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Foundation\User\Models\User;

class UserLoggedOut
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly User $user) {}
}
