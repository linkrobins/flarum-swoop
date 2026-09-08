<?php

namespace LinkRobins\Swoop;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\User\AccountActivationMailer;
use Flarum\User\EmailConfirmationMailer;
use LinkRobins\Swoop\Mailer\ActivationMailer;
use LinkRobins\Swoop\Mailer\EmailChangeMailer;

/**
 * Swaps core's two account mailers for ours.
 *
 * Core wires them as event listeners resolved from the container by class name,
 * so rebinding the class is all it takes. The client is injected afterwards
 * rather than through the constructor, because these subclasses must keep
 * core's constructor signature to stay resolvable.
 */
class SwoopServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        foreach ([
            AccountActivationMailer::class  => ActivationMailer::class,
            EmailConfirmationMailer::class  => EmailChangeMailer::class,
        ] as $core => $ours) {
            $this->container->bind($core, function ($container) use ($ours) {
                $mailer = $container->make($ours);
                $mailer->setSwoop($container->make(SwoopClient::class));

                return $mailer;
            });
        }
    }
}
