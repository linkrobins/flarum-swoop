<?php

namespace LinkRobins\Swoop\Mail;

use Flarum\Mail\DriverInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Support\MessageBag;
use LinkRobins\Swoop\SwoopClient;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Swoop as a first-class mail driver, chosen in Admin → Email beside SMTP.
 *
 * ⚠️ This is a driver, and a driver is the forum's mail. Where the old
 * three-mailer version fell back to the forum's own settings whenever the
 * service refused — so installing it could only help — choosing this one means
 * Swoop carries everything: account mail, notifications, whatever an extension
 * sends. If it is unreachable, mail fails, exactly as it would with a broken
 * SMTP host. That is the trade for being a real driver rather than a wrapper.
 */
class SwoopDriver implements DriverInterface
{
    public function __construct(private SwoopClient $client)
    {
    }

    public function availableSettings(): array
    {
        // The key is edited on the extension's own page, where saving it checks
        // it against the service. Repeating it here would give an admin two
        // fields for one value and no clue which one wins.
        return [];
    }

    public function validate(SettingsRepositoryInterface $settings, Factory $validator): MessageBag
    {
        $errors = new MessageBag();

        if (trim((string) $settings->get('linkrobins-swoop.key')) === '') {
            $errors->add('linkrobins-swoop.key', 'Add your Swoop key on the Swoop settings page before choosing it here.');
        }

        return $errors;
    }

    public function canSend(): bool
    {
        return true;
    }

    public function buildTransport(SettingsRepositoryInterface $settings): TransportInterface
    {
        return new SwoopTransport($this->client);
    }
}
