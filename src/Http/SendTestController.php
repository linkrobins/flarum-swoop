<?php

namespace LinkRobins\Swoop\Http;

use Flarum\Foundation\Config;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Swoop\SwoopClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Sends the admin a test email, so "is this working?" has an answer that does
 * not involve registering a fake account.
 *
 * It goes to the admin's own address and nowhere else: the endpoint takes no
 * recipient, which keeps a compromised admin session from turning this into a
 * way to mail strangers. The link is the forum itself, which is also the only
 * link the service will accept for this key.
 *
 * On failure it returns the reason rather than a bare false. The client already
 * records why a call was refused — wrong key, spent quota, wrong host — and that
 * sentence is the entire value of a test button.
 */
class SendTestController implements \Psr\Http\Server\RequestHandlerInterface
{
    public function __construct(
        private SwoopClient $client,
        private Config $config,
        private SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $to = trim((string) RequestUtil::getActor($request)->email);

        if ($to === '') {
            return new JsonResponse([
                'sent'  => false,
                'error' => 'Your account has no email address to send to.',
            ], 422);
        }

        if (! $this->client->connected()) {
            // "Save a key first" is the right advice only when there is no key.
            // The commonest way to be disconnected is a key that WAS saved and
            // was refused, or an address pointed at a host that has never heard
            // of Swoop — and the exchange already recorded which. Repeating the
            // generic line there would send an admin to re-paste a key that was
            // never the problem.
            $why = trim((string) $this->settings->get('linkrobins-swoop.last-error'));

            return new JsonResponse([
                'sent'  => false,
                'error' => $why !== '' ? $why : 'Not connected yet — save a key first.',
            ], 409);
        }

        $sent = $this->client->send('test', $to, rtrim((string) $this->config->url(), '/'));

        if (! $sent) {
            return new JsonResponse([
                'sent'  => false,
                // Written by the client when the call was refused; the fallback
                // only matters if something failed without saying why.
                'error' => (string) $this->settings->get('linkrobins-swoop.last-error')
                    ?: 'The service refused the send and gave no reason.',
            ], 424);
        }

        return new JsonResponse(['sent' => true, 'to' => $to]);
    }
}
