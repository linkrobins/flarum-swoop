<?php

namespace LinkRobins\Swoop\Listener;

use Flarum\Settings\Event\Saved;
use Flarum\Settings\SettingsRepositoryInterface;
use LinkRobins\Swoop\SwoopClient;

/**
 * When an admin saves a mail key, exchange it straight away.
 *
 * Doing this on save rather than on the first email matters: an admin finds out
 * on the settings page whether the key works, instead of discovering it when a
 * new member's activation email quietly fails.
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
        if (!array_key_exists('linkrobins-swoop.key', $event->settings)) {
            return;
        }

        $key = trim((string) $event->settings['linkrobins-swoop.key']);

        if ($key === '') {
            $this->settings->set('linkrobins-swoop.connected', '0');
            $this->settings->set('linkrobins-swoop.status', '');

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
