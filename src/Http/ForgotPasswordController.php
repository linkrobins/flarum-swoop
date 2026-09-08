<?php

namespace LinkRobins\Swoop\Http;

use Flarum\Api\Controller\ForgotPasswordController as CoreController;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use LinkRobins\Swoop\Job\RequestPasswordResetJob;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Pushes Swoop's reset job instead of core's.
 *
 * Core dispatches its job with `new`, so no container binding would swap it;
 * replacing the controller that pushes it is the seam. Validation and the empty
 * response are unchanged, including the deliberate refusal to reveal whether
 * the address exists.
 */
class ForgotPasswordController extends CoreController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getParsedBody();

        $this->validator->assertValid($params);

        $this->queue->push(new RequestPasswordResetJob(Arr::get($params, 'email')));

        return new EmptyResponse;
    }
}
