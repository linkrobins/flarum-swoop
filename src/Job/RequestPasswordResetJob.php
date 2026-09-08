<?php

namespace LinkRobins\Swoop\Job;

use Flarum\Http\UrlGenerator;
use Flarum\Queue\AbstractJob;
use Flarum\User\PasswordToken;
use Flarum\User\User;
use Illuminate\Contracts\Queue\Queue;
use LinkRobins\Swoop\SwoopClient;

/**
 * The password-reset email, sent through Swoop.
 *
 * Queued for the same reason core queues it: replying immediately regardless of
 * whether the address exists means the response time cannot be used to discover
 * who has an account here.
 *
 * When Swoop is not connected or refuses, this defers to core's own job so the
 * forum keeps working exactly as it did.
 */
class RequestPasswordResetJob extends AbstractJob
{
    public function __construct(private string $email)
    {
    }

    public function handle(SwoopClient $swoop, UrlGenerator $url, Queue $queue): void
    {
        if (!$swoop->connected()) {
            $queue->push(new \Flarum\User\Job\RequestPasswordResetJob($this->email));

            return;
        }

        $user = User::where('email', $this->email)->first();
        if (!$user) {
            // Silence is deliberate: saying nothing is what stops this endpoint
            // being used to test whether an address has an account here.
            return;
        }

        $token = PasswordToken::generate($user->id);
        $token->save();

        $link = $url->to('forum')->route('resetPassword', ['token' => $token->token]);

        if (!$swoop->send('password_reset', (string) $user->email, $link)) {
            $queue->push(new \Flarum\User\Job\RequestPasswordResetJob($this->email));
        }
    }
}
