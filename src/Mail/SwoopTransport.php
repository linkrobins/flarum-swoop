<?php

namespace LinkRobins\Swoop\Mail;

use LinkRobins\Swoop\SwoopClient;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Mime\MessageConverter;

/**
 * Hands a finished email to Link Robins over HTTPS.
 *
 * By the time a transport is called Flarum has already done everything: its
 * templates, its translations in the recipient's locale, its safe-value
 * substitution put back. There is nothing left to render, which is the point —
 * being a transport rather than a mailer is what makes "we only deliver it"
 * literally true.
 *
 * Port 443, like every other API transport Symfony ships. A host that blocks
 * SMTP never enters into it.
 */
class SwoopTransport extends AbstractTransport
{
    public function __construct(private SwoopClient $client)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $sent): void
    {
        $email = $this->asEmail($sent->getOriginalMessage());

        if ($email === null) {
            // Somebody handed the mailer a pre-built MIME string rather than a
            // message object. Flarum never does, but the type allows it, and
            // there is nothing honest to do with an opaque blob: the service
            // takes a subject and a body, not an envelope we cannot read.
            throw new TransportException('Swoop cannot relay a raw MIME message.');
        }

        $to = array_map(fn ($a) => $a->getAddress(), $email->getTo());

        if (! $to) {
            // Nothing to do, and not an error worth failing a queue job over.
            return;
        }

        $failure = $this->client->deliver([
            'to'       => implode(',', $to),
            'subject'  => (string) $email->getSubject(),
            'html'     => (string) $email->getHtmlBody(),
            'text'     => (string) $email->getTextBody(),
            'reply_to' => implode(',', array_map(fn ($a) => $a->getAddress(), $email->getReplyTo())),
        ]);

        if ($failure !== null) {
            // Thrown, not swallowed. A transport that quietly drops mail leaves
            // a forum believing its email works; Flarum's queue will retry this
            // and surface it.
            throw new TransportException('Swoop refused the message: '.$failure);
        }
    }

    private function asEmail(RawMessage $message): ?Email
    {
        if ($message instanceof Email) {
            return $message;
        }

        return $message instanceof Message ? MessageConverter::toEmail($message) : null;
    }

    public function __toString(): string
    {
        return 'swoop://linkrobins';
    }
}
