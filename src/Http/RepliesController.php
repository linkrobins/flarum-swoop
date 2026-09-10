<?php

namespace LinkRobins\Swoop\Http;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Swoop\SwoopClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/swoop/replies — what members have written back.
 *
 * Fetching them marks them read, because an admin who has been shown a reply
 * has read it and a second call to say so is ceremony.
 */
class RepliesController implements RequestHandlerInterface
{
    public function __construct(private SwoopClient $client)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return new JsonResponse(['replies' => $this->client->replies(markRead: true)]);
    }
}
