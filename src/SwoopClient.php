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
    // 'test' is the admin proving the path to themselves from the settings
    // page. It is a real send and counts against quota like the rest.
    public const TYPES = ['activation', 'password_reset', 'email_change', 'test'];

    /**
     * Where the service answers unless a forum overrides it.
     *
     * Kept here rather than repeated at each use so that the extender default
     * and the fallback below can never drift apart.
     */
    public const DEFAULT_SERVICE_URL = 'https://linkrobins.com';

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
            'token'       => $key,
            'forum_url'   => rtrim((string) $this->config->url(), '/'),
            'forum_title' => $this->forumTitle(),
        ]);

        return ($body && !empty($body['connected'])) ? $body : null;
    }

    /**
     * Ask the service where this key stands.
     *
     * Same call as connect(), which already answers with the balance -- there is
     * no second endpoint to keep in step, and re-reporting the url and title
     * while we are here costs nothing and keeps them fresh.
     *
     * @return array{balance?:int,name?:string,connected?:bool}|null
     */
    public function status(): ?array
    {
        $key = $this->key();

        return $key === '' ? null : $this->connect($key);
    }

    /**
     * Send one account email.
     *
     * @param string $type One of self::TYPES.
     * @param string $link Must be a url on this forum; the service rejects others.
     * @param array{subject?:string,html?:string,text?:string} $message The email
     *        as the forum rendered it. Every link in it must be on this forum.
     * @return bool True when the service accepted it.
     */
    public function send(string $type, string $to, string $link, array $message = []): bool
    {
        if (!$this->connected() || !in_array($type, self::TYPES, true)) {
            return false;
        }

        $body = $this->post('/mail/send', array_filter([
            'token'       => $this->key(),
            'type'        => $type,
            'to'          => $to,
            'link'        => $link,
            // The finished email, rendered by the forum. The service delivers
            // it as-is; it does not write one of its own. Absent only if the
            // caller had nothing rendered, in which case the service falls
            // back to its own copy.
            'subject'     => $message['subject'] ?? null,
            'html'        => $message['html'] ?? null,
            'text'        => $message['text'] ?? null,
            // Sent every time, not just at connect: a forum that renames itself
            // should be right in the next email rather than whenever somebody
            // happens to re-save their key.
            'forum_title' => $this->forumTitle(),
        ], fn ($v) => $v !== null && $v !== ''));

        return (bool) ($body['sent'] ?? false);
    }

    /**
     * What people have written back to this forum's account emails.
     *
     * @return array<int,array<string,mixed>>
     */
    public function replies(bool $markRead = false): array
    {
        if (!$this->connected()) {
            return [];
        }

        $body = $this->post('/mail/replies', array_filter([
            'token'    => $this->key(),
            'markRead' => $markRead ? '1' : null,
        ]));

        return (array) ($body['replies'] ?? []);
    }

    /**
     * Hand over a message the forum has already finished.
     *
     * Null when the service took it, or the reason it did not — the transport
     * turns that into an exception, so a failure is visible rather than a
     * silently missing email.
     *
     * @param array{to:string,subject:string,html:string,text:string,reply_to:string} $message
     */
    public function deliver(array $message): ?string
    {
        if (! $this->connected()) {
            return 'no Swoop key is connected';
        }

        $body = $this->post('/mail/deliver', array_filter([
            'token'       => $this->key(),
            'forum_title' => $this->forumTitle(),
            'to'          => $message['to'],
            'subject'     => $message['subject'],
            'html'        => $message['html'],
            'text'        => $message['text'],
            'reply_to'    => $message['reply_to'],
        ], fn ($v) => $v !== null && $v !== ''));

        if ($body && ! empty($body['sent'])) {
            return null;
        }

        return (string) ($this->settings->get('linkrobins-swoop.last-error') ?: 'the service refused the message');
    }

    private function post(string $path, array $params): ?array
    {
        $base = $this->serviceUrl();
        $url  = $base . $path;

        try {
            $res = $this->http->post($url, [
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
                //
                // A host that is not the service at all answers in its own
                // shape, or in no shape: a wrong url typically gives a 404 or a
                // 405 with Laravel's `message`, or an HTML error page that
                // decodes to nothing. Record the status and the address in that
                // case, because "405 from https://example.com/mail/config" is
                // what tells an admin they are pointed at the wrong host, and
                // an empty banner tells them nothing.
                $reason = is_array($body)
                    ? ($body['error'] ?? $body['message'] ?? null)
                    : null;

                $this->log->warning('Swoop: ' . $path . ' refused', [
                    'url'    => $url,
                    'status' => $res->getStatusCode(),
                    'reason' => $reason,
                ]);

                $this->rememberError($reason !== null
                    ? (string) $reason
                    : 'The service did not answer at ' . $url . ' (HTTP ' . $res->getStatusCode() . ').');

                return null;
            }

            $this->settings->set('linkrobins-swoop.last-error', '');

            return is_array($body) ? $body : null;
        } catch (Throwable $e) {
            $this->log->warning('Swoop: ' . $path . ' threw', ['url' => $url, 'error' => $e->getMessage()]);

            // Nothing answered at all: an unreachable host, DNS that does not
            // resolve, a timeout. Previously this was logged and nowhere else,
            // so the settings page said "not connected" with no reason on it.
            $this->rememberError('Could not reach the service at ' . $url . ': ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The service address, normalised.
     *
     * An admin who types a bare host has given a usable answer, so treat it as
     * one rather than letting the http client throw on a url with no scheme.
     */
    /**
     * This forum's own name, for the service to put in the email.
     *
     * The name a member reads has to come from the forum, not from a label
     * somebody typed on our side when the key was made. This is the same
     * setting the forum shows in its own header.
     */
    private function forumTitle(): string
    {
        return mb_substr(trim((string) $this->settings->get('forum_title')), 0, 120);
    }

    public function serviceBase(): string
    {
        return $this->serviceUrl();
    }

    private function serviceUrl(): string
    {
        $url = trim((string) $this->settings->get('linkrobins-swoop.service-url'));

        if ($url === '') {
            $url = self::DEFAULT_SERVICE_URL;
        }

        if (! preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }

        return rtrim($url, '/');
    }

    private function rememberError(string $error): void
    {
        $this->settings->set('linkrobins-swoop.last-error', mb_substr($error, 0, 255));
    }

    private function key(): string
    {
        return trim((string) $this->settings->get('linkrobins-swoop.key'));
    }
}
