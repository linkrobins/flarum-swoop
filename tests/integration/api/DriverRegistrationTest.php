<?php

namespace LinkRobins\Swoop\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use LinkRobins\Swoop\Mail\SwoopDriver;
use LinkRobins\Swoop\SwoopClient;
use PHPUnit\Framework\Attributes\Test;

/**
 * That Flarum actually offers Swoop as a mail driver is the whole extension.
 *
 * A driver that fails to register does not error — it simply never appears in
 * Admin → Email, so a forum keeps using whatever it had while every setting
 * here insists otherwise. Registration is the one thing a unit test cannot see.
 */
class DriverRegistrationTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-swoop');
    }

    #[Test]
    public function swoop_is_offered_as_a_mail_driver(): void
    {
        $this->app();

        $drivers = $this->app()->getContainer()->make('mail.supported_drivers');

        $this->assertArrayHasKey('swoop', $drivers);
        $this->assertSame(SwoopDriver::class, $drivers['swoop']);
    }

    #[Test]
    public function the_driver_builds_a_transport(): void
    {
        $this->app();

        $driver = $this->app()->getContainer()->make(SwoopDriver::class);

        // A driver that cannot build its transport is a driver a forum can
        // select and then discover is broken the next time somebody registers.
        $this->assertTrue($driver->canSend());
        $this->assertInstanceOf(
            \Symfony\Component\Mailer\Transport\TransportInterface::class,
            $driver->buildTransport($this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class))
        );
    }

    #[Test]
    public function an_unconfigured_forum_is_not_connected(): void
    {
        $this->app();

        // With no key pasted, the client must report itself unconnected so
        // the driver reports itself unconfigured rather than sending.
        $this->assertFalse($this->app()->getContainer()->make(SwoopClient::class)->connected());
    }
}
