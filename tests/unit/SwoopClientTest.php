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

    protected function tearDown(): void
    {
        Mockery::close();
        $this->history = [];
    }

    private function client(array $responses, array $settings = []): SwoopClient
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $repo = Mockery::mock(SettingsRepositoryInterface::class);
        $repo->shouldReceive('get')->andReturnUsing(fn ($k) => $settings[$k] ?? null);
        $repo->shouldReceive('set')->andReturnNull();

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
}
