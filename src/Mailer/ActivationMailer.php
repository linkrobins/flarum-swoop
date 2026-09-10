<?php

namespace LinkRobins\Swoop\Mailer;

use Flarum\User\AccountActivationMailer;
use Flarum\User\User;
use Illuminate\Support\Arr;
use LinkRobins\Swoop\NativeMessage;
use LinkRobins\Swoop\SwoopClient;

/**
 * Sends the "confirm your account" email through Swoop instead of the forum's
 * own mailer.
 *
 * This is the one that matters most. A forum whose SMTP is blocked looks broken
 * to its very first visitor, because registration completes and the activation
 * email never arrives.
 *
 * Falls back to core's behaviour whenever Swoop is not connected or refuses, so
 * a forum is never worse off than before installing this.
 */
class ActivationMailer extends AccountActivationMailer
{
    protected ?SwoopClient $swoop = null;
    protected ?NativeMessage $native = null;

    public function setSwoop(SwoopClient $swoop): void
    {
        $this->swoop = $swoop;
    }

    public function setNative(NativeMessage $native): void
    {
        $this->native = $native;
    }

    protected function sendConfirmationEmail(User $user, array $data): void
    {
        $link = (string) Arr::get($data, 'url');

        // Rendered by the forum, in the recipient's own language, from the
        // forum's own templates. We deliver it; we do not write it.
        $message = $this->native?->render(
            'core.email.activate_account.subject',
            'core.email.activate_account.body',
            $data,
            (string) $user->email,
            (string) Arr::get($data, 'username'),
            $user->getPreference('locale')
        ) ?? [];

        if ($this->swoop?->send('activation', (string) $user->email, $link, $message)) {
            return;
        }

        parent::sendConfirmationEmail($user, $data);
    }
}
