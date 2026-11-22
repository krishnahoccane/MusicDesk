<?php

namespace LabelDesk\Addons\Sso\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Validated;
use Illuminate\Validation\ValidationException;
use LabelDesk\Addons\Sso\SsoPolicy;

/**
 * Password sign-in for staff who must use single sign-on. Runs after the
 * password was checked and before the session is created, so it reveals
 * nothing to someone who does not know the password.
 */
class RequireSingleSignOn
{
    public function __construct(protected SsoPolicy $policy) {}

    public function handle(Validated $event): void
    {
        if ($event->guard === 'web' && $event->user instanceof User && $this->policy->passwordBlockedFor($event->user)) {
            throw ValidationException::withMessages([
                'email' => __('Your organisation requires single sign-on. Use a sign-in button below.'),
            ]);
        }
    }
}
