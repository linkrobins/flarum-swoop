<?php

namespace LinkRobins\Swoop\Tests\unit;

use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LinkRobins\Swoop\SwoopClient;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The client is where the guardrails live on this side, so that is what these
 * cover: it must not send when it is not connected, it must not carry a type
 * the service does not know, and it must never put a subject or body on the
 * wire — the absence of those fields is what stops a leaked key becoming a
 * relay.
 */
class SwoopClientTest extends TestCase
{
    private array $history = [];

    /** @var array<string, mixed> */
    private array $written = [];

    protected function tearDown(): void
    {
        Mockery::close();
        $this->history = [];
        $this->written = [];
    }

    private function client(array $responses, array $settings = []): SwoopClient
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $repo = Mockery::mock(SettingsRepositoryInterface::class);
        $repo->shouldReceive('get')->andReturnUsing(fn ($k) => $settings[$k] ?? null);
        $repo->shouldReceive('set')->andReturnUsing(function ($k, $v) {
            $this->written[$k] = $v;

            return null;
        });

        return new SwoopClient(
            $repo,
            new Client(['handler' => $stack]),
            new Config(['url' => 'https://forum.example.test']),
            new NullLogger()
        );
    }

    private function connectedSettings(): array
    {
        return [
            'linkrobins-swoop.key'         => 'KEY-123',
            'linkrobins-swoop.connected'   => '1',
            'linkrobins-swoop.service-url' => 'https://service.test',
        ];
    }

    #[Test]
    public function connecting_reports_this_forums_own_url(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['connected' => true, 'name' => 'Forum']))]);

        $this->assertIsArray($client->connect('KEY-123'));

        $request = $this->history[0]['request'];
        $body    = (string) $request->getBody();

        $this->assertSame('https://linkrobins.com/mail/config', (string) $request->getUri());
        $this->assertStringContainsString('token=KEY-123', $body);
        // The forum reporting its own address is what makes the service's link
        // check mean anything.
        $this->assertStringContainsString('forum_url=https%3A%2F%2Fforum.example.test', $body);
    }

    #[Test]
    public function a_refused_key_is_not_treated_as_connected(): void
    {
        $client = $this->client([new Response(404, [], json_encode(['error' => 'invalid or inactive key']))]);

        $this->assertNull($client->connect('BAD'));
    }

    #[Test]
    public function an_unconnected_forum_never_calls_the_service(): void
    {
        $client = $this->client([], ['linkrobins-swoop.connected' => '0']);

        $this->assertFalse($client->send('activation', 'a@b.test', 'https://forum.example.test/x'));
        $this->assertCount(0, $this->history);
    }

    #[Test]
    public function an_unknown_type_never_reaches_the_wire(): void
    {
        $client = $this->client([], $this->connectedSettings());

        $this->assertFalse($client->send('newsletter', 'a@b.test', 'https://forum.example.test/x'));
        $this->assertCount(0, $this->history);
    }

    #[Test]
    public function a_send_carries_a_type_and_a_link_and_nothing_else(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['sent' => true]))], $this->connectedSettings());

        $this->assertTrue($client->send('activation', 'someone@b.test', 'https://forum.example.test/confirm/1'));

        $body = (string) $this->history[0]['request']->getBody();
        $this->assertStringContainsString('type=activation', $body);
        $this->assertStringContainsString('token=KEY-123', $body);

        // The point of the whole design: there is no field here that could
        // carry an attacker's message.
        foreach (['subject', 'body', 'html', 'text', 'from', 'sender'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden . '=', $body);
        }
    }

    #[Test]
    public function the_test_type_is_allowed_and_still_carries_no_message_fields(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['sent' => true]))], $this->connectedSettings());

        // The admin's own "is this working?" send. It is a real email, so it
        // goes through exactly the same narrow door as the account mail.
        $this->assertTrue($client->send('test', 'admin@b.test', 'https://forum.example.test'));

        $body = (string) $this->history[0]['request']->getBody();
        $this->assertStringContainsString('type=test', $body);

        foreach (['subject', 'body', 'html', 'text', 'from', 'sender'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden . '=', $body);
        }
    }

    #[Test]
    public function a_service_refusal_is_reported_as_not_sent(): void
    {
        $client = $this->client(
            [new Response(429, [], json_encode(['error' => 'this key has reached its monthly quota']))],
            $this->connectedSettings()
        );

        $this->assertFalse($client->send('activation', 'a@b.test', 'https://forum.example.test/x'));
    }

    #[Test]
    public function a_transport_failure_is_false_not_a_throw(): void
    {
        $client = $this->client(
            [new \GuzzleHttp\Exception\ConnectException('down', new \GuzzleHttp\Psr7\Request('POST', 'https://service.test'))],
            $this->connectedSettings()
        );

        // A forum whose mail service is unreachable must fall back, not throw
        // in the middle of somebody registering.
        $this->assertFalse($client->send('activation', 'a@b.test', 'https://forum.example.test/x'));
    }

    #[Test]
    public function the_configured_service_address_is_the_one_called(): void
    {
        $client = $this->client(
            [new Response(200, [], json_encode(['connected' => true]))],
            ['linkrobins-swoop.service-url' => 'https://elsewhere.test/']
        );

        $client->connect('KEY-123');

        $this->assertSame('https://elsewhere.test/mail/config', (string) $this->history[0]['request']->getUri());
    }

    #[Test]
    public function an_address_without_a_scheme_is_still_usable(): void
    {
        // An admin who types a bare host has answered the question; the http
        // client would otherwise throw on a url with no scheme.
        $client = $this->client(
            [new Response(200, [], json_encode(['connected' => true]))],
            ['linkrobins-swoop.service-url' => 'elsewhere.test']
        );

        $client->connect('KEY-123');

        $this->assertSame('https://elsewhere.test/mail/config', (string) $this->history[0]['request']->getUri());
    }

    #[Test]
    public function a_host_that_is_not_the_service_says_so_instead_of_saying_nothing(): void
    {
        // What the wrong host actually returns: Laravel's 405, whose body has
        // `message` and no `error`. Before, nothing was recorded at all and the
        // settings page reported "not connected" with no reason on it.
        $client = $this->client(
            [new Response(405, [], json_encode(['message' => 'The POST method is not supported for route mail/config.']))],
            ['linkrobins-swoop.service-url' => 'https://wrong.test']
        );

        $this->assertNull($client->connect('KEY-123'));
        $this->assertStringContainsString(
            'The POST method is not supported',
            (string) $this->written['linkrobins-swoop.last-error']
        );
    }

    #[Test]
    public function a_body_that_explains_nothing_still_names_the_address_and_the_status(): void
    {
        $client = $this->client(
            [new Response(404, [], '<html><body>Not Found</body></html>')],
            ['linkrobins-swoop.service-url' => 'https://wrong.test']
        );

        $this->assertNull($client->connect('KEY-123'));

        $recorded = (string) $this->written['linkrobins-swoop.last-error'];
        $this->assertStringContainsString('https://wrong.test/mail/config', $recorded);
        $this->assertStringContainsString('404', $recorded);
    }

    #[Test]
    public function a_host_that_does_not_answer_is_recorded_too(): void
    {
        $client = $this->client(
            [new \GuzzleHttp\Exception\ConnectException('dns failed', new \GuzzleHttp\Psr7\Request('POST', 'https://gone.test'))],
            ['linkrobins-swoop.service-url' => 'https://gone.test']
        );

        $this->assertNull($client->connect('KEY-123'));
        $this->assertStringContainsString('https://gone.test/mail/config', (string) $this->written['linkrobins-swoop.last-error']);
    }
}
