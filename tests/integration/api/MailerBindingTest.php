<?php

namespace LinkRobins\Swoop\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\AccountActivationMailer;
use Flarum\User\EmailConfirmationMailer;
use LinkRobins\Swoop\Mailer\ActivationMailer;
use LinkRobins\Swoop\Mailer\EmailChangeMailer;
use LinkRobins\Swoop\SwoopClient;
use PHPUnit\Framework\Attributes\Test;

/**
 * That the mailers are actually swapped is the whole extension.
 *
 * Core resolves both from the container when it wires them as event listeners,
 * so a binding that silently fails to apply would leave the forum sending its
 * own mail while every setting insisted otherwise — working, but doing nothing.
 * These assert the swap really happened, which is the one thing a unit test
 * cannot see.
 */
class MailerBindingTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-swoop');
    }

    #[Test]
    public function the_activation_mailer_is_ours(): void
    {
        $this->app();

        $this->assertInstanceOf(
            ActivationMailer::class,
            $this->app()->getContainer()->make(AccountActivationMailer::class)
        );
    }

    #[Test]
    public function the_email_change_mailer_is_ours(): void
    {
        $this->app();

        $this->assertInstanceOf(
            EmailChangeMailer::class,
            $this->app()->getContainer()->make(EmailConfirmationMailer::class)
        );
    }

    #[Test]
    public function an_unconfigured_forum_is_not_connected(): void
    {
        $this->app();

        // With no key pasted, the client must report itself unconnected so
        // every mailer falls through to the forum's own settings.
        $this->assertFalse($this->app()->getContainer()->make(SwoopClient::class)->connected());
    }
}
