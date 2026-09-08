<?php

namespace LinkRobins\Swoop;

use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Talks to the Swoop service.
 *
 * Two calls. `connect()` runs when an admin pastes a key: the service confirms
 * it and records THIS forum's address, which every later message is checked
 * against. `send()` asks for one account email.
 *
 * Note what is not here: no subject, no body, no sender. The service renders
 * the message from a type, which is what stops a leaked key being usable as a
 * relay. There is deliberately no method that would let this forum, or anything
 * that compromised it, send arbitrary mail.
 *
 * Everything fails soft. A forum whose mail service is unreachable should fall
 * back to its own mailer, not throw during registration.
 */
class SwoopClient
{
    public const TYPES = ['activation', 'password_reset', 'email_change'];

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private Client $http,
        private Config $config,
        private LoggerInterface $log
    ) {
    }

    public function connected(): bool
    {
        return $this->key() !== '' && $this->settings->get('linkrobins-swoop.connected') === '1';
    }

    /** Confirm the key and register this forum's url. Null on any failure. */
    public function connect(string $key): ?array
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        $body = $this->post('/mail/config', [
            'token'     => $key,
            'forum_url' => rtrim((string) $this->config->url(), '/'),
        ]);

        return ($body && !empty($body['connected'])) ? $body : null;
    }

    /**
     * Send one account email.
     *
     * @param string $type One of self::TYPES.
     * @param string $link Must be a url on this forum; the service rejects others.
     * @return bool True when the service accepted it.
     */
    public function send(string $type, string $to, string $link): bool
    {
        if (!$this->connected() || !in_array($type, self::TYPES, true)) {
            return false;
        }

        $body = $this->post('/mail/send', [
            'token' => $this->key(),
            'type'  => $type,
            'to'    => $to,
            'link'  => $link,
        ]);

        return (bool) ($body['sent'] ?? false);
    }

    private function post(string $path, array $params): ?array
    {
        $base = rtrim((string) ($this->settings->get('linkrobins-swoop.service-url') ?: 'https://linkrobins.com'), '/');

        try {
            $res = $this->http->post($base . $path, [
                'form_params'     => $params,
                'headers'         => ['Accept' => 'application/json'],
                'connect_timeout' => 3,
                'timeout'         => 8,
                'http_errors'     => false,
            ]);

            $body = json_decode((string) $res->getBody(), true);

            if ($res->getStatusCode() !== 200) {
                // The service explains itself in `error`; keep that, because
                // "quota reached" and "service down" want different responses
                // from an admin.
                $this->log->warning('Swoop: ' . $path . ' refused', [
                    'status' => $res->getStatusCode(),
                    'reason' => is_array($body) ? ($body['error'] ?? null) : null,
                ]);

                if (is_array($body) && isset($body['error'])) {
                    $this->settings->set('linkrobins-swoop.last-error', mb_substr((string) $body['error'], 0, 255));
                }

                return null;
            }

            $this->settings->set('linkrobins-swoop.last-error', '');

            return is_array($body) ? $body : null;
        } catch (Throwable $e) {
            $this->log->warning('Swoop: ' . $path . ' threw', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function key(): string
    {
        return trim((string) $this->settings->get('linkrobins-swoop.key'));
    }
}
