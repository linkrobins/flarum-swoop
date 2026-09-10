<?php

namespace LinkRobins\Swoop\Http;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Swoop\SwoopClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * What the settings page needs to draw the meter: emails left, and where to
 * buy more.
 *
 * Asked live rather than read from a stored setting, because a balance that is
 * only refreshed when the forum happens to send is exactly wrong for the one
 * screen somebody opens to find out how many they have left.
 */
class StatusController implements RequestHandlerInterface
{
    public function __construct(private SwoopClient $client)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $status = $this->client->status();

        if ($status === null) {
            return new JsonResponse(['connected' => false, 'balance' => null], 200);
        }

        return new JsonResponse([
            'connected' => true,
            'balance'   => (int) ($status['balance'] ?? 0),
            'name'      => (string) ($status['name'] ?? ''),
            'stats'     => (array) ($status['stats'] ?? []),
            'unread'    => (int) ($status['replies'] ?? 0),
            'topUpUrl'  => $this->client->serviceBase() . '/dashboard/swoop',
        ]);
    }
}
