<?php

namespace Modules\Foundation\Authentication\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\SerializesModels;
use Modules\Foundation\User\Models\User;

class UserLoggedIn
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly Request $request
    ) {}
}
