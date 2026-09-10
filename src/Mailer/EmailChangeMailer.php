<?php

namespace LinkRobins\Swoop\Mailer;

use Flarum\User\EmailConfirmationMailer;
use Flarum\User\Event\EmailChangeRequested;
use LinkRobins\Swoop\NativeMessage;
use LinkRobins\Swoop\SwoopClient;

/**
 * Sends the "confirm your new address" email through Swoop.
 *
 * Core builds and queues this inline rather than through a shared method, so
 * the whole handler is replaced. The token still comes from core, so the link
 * is exactly the one Flarum would have sent.
 */
class EmailChangeMailer extends EmailConfirmationMailer
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

    public function handle(EmailChangeRequested $event): void
    {
        if (!$this->swoop?->connected()) {
            parent::handle($event);

            return;
        }

        $email = $event->email;
        $token = $this->generateToken($event->user, $email);
        $data  = $this->getEmailData($event->user, $email);

        $message = $this->native?->render(
            'core.email.confirm_email.subject',
            'core.email.confirm_email.body',
            $data,
            $email,
            (string) ($data['username'] ?? ''),
            $event->user->getPreference('locale')
        ) ?? [];

        if (!$this->swoop->send('email_change', $email, (string) ($data['url'] ?? ''), $message)) {
            // Hand the event back rather than leave the person with no email
            // at all. Core mints a fresh token; the one above is simply never
            // used and expires on its own.
            parent::handle($event);
        }
    }
}
