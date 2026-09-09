<?php

namespace LinkRobins\Swoop\Listener;

use Flarum\Settings\Event\Saved;
use Flarum\Settings\SettingsRepositoryInterface;
use LinkRobins\Swoop\SwoopClient;

/**
 * When an admin saves a mail key, or moves the forum to a different service
 * address, exchange the key straight away.
 *
 * Doing this on save rather than on the first email matters: an admin finds out
 * on the settings page whether the key works, instead of discovering it when a
 * new member's activation email quietly fails. The address is included for the
 * same reason. A url that is not the service answers like anything else on the
 * web, so the only way to know it is wrong is to ask it, and the moment of
 * saving it is when an admin is looking.
 *
 * The exchange also registers this forum's own address with the service, which
 * is what every later message is checked against.
 */
class ExchangeKeyOnSave
{
    public function __construct(
        private SettingsRepositoryInterface $settings,
        private SwoopClient $swoop
    ) {
    }

    public function handle(Saved $event): void
    {
        $touchedKey = array_key_exists('linkrobins-swoop.key', $event->settings);
        $touchedUrl = array_key_exists('linkrobins-swoop.service-url', $event->settings);

        if (!$touchedKey && !$touchedUrl) {
            return;
        }

        // Changing only the address re-tests the key already stored, which is
        // the case an admin is in while they are correcting a wrong host.
        $key = trim((string) ($touchedKey
            ? $event->settings['linkrobins-swoop.key']
            : $this->settings->get('linkrobins-swoop.key')));

        if ($key === '') {
            $this->settings->set('linkrobins-swoop.connected', '0');
            $this->settings->set('linkrobins-swoop.status', '');
            $this->settings->set('linkrobins-swoop.last-error', '');

            return;
        }

        $config = $this->swoop->connect($key);

        $this->settings->set('linkrobins-swoop.connected', $config ? '1' : '0');
        $this->settings->set('linkrobins-swoop.status', $config ? json_encode([
            'name'  => $config['name'] ?? '',
            'plan'  => $config['plan'] ?? '',
            'quota' => $config['quota'] ?? 0,
            'used'  => $config['used'] ?? 0,
        ]) : '');
    }
}
