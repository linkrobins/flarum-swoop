<?php

namespace LinkRobins\Swoop\Mail;

use LinkRobins\Swoop\SwoopClient;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\RawMessage;

/**
 * Hands a finished email to Link Robins over HTTPS.
 *
 * By the time a transport is called Flarum has done everything: its templates,
 * its translations in the recipient's locale, its safe-value substitution put
 * back, and the listeners on MessageSending have added their headers. There is
 * nothing left to render, which is what makes "we only deliver it" true.
 *
 * Port 443, like every other API transport Symfony ships, so a host that blocks
 * SMTP never enters into it.
 */
class SwoopTransport extends AbstractTransport
{
    /**
     * Headers Postmark takes as its own fields, so forwarding them again would
     * duplicate or fight them. Everything else the forum set is carried.
     */
    private const OWN_FIELDS = [
        'from', 'to', 'cc', 'bcc', 'subject', 'reply-to',
        'content-type', 'sender', 'date', 'message-id', 'mime-version',
    ];

    public function __construct(private SwoopClient $client)
    {
        // Not optional. AbstractTransport declares typed $dispatcher and
        // $logger and initialises them here; skipping it fails at send with
        // "must not be accessed before initialization" — from inside a queue
        // job, so the forum just sees mail not arriving.
        parent::__construct();
    }

    protected function doSend(SentMessage $sent): void
    {
        $email = $this->asEmail($sent->getOriginalMessage());

        if ($email === null) {
            // Somebody handed the mailer a pre-built MIME string. Flarum never
            // does, but the type allows it and there is nothing honest to do
            // with an opaque blob: the service takes a subject and a body.
            throw new TransportException('Swoop cannot relay a raw MIME message.');
        }

        if ($email->getAttachments()) {
            // Refused rather than quietly stripped. A recipient reading "see
            // the attached invoice" with nothing attached is worse than a
            // failure somebody can see and act on.
            throw new TransportException('Swoop does not carry attachments yet; this message has one.');
        }

        // Recipients come from the envelope, not the Email. An explicit
        // envelope passed to Mailer::send() is authoritative, and Bcc lives
        // only there — reading getTo() would drop it and silently discard a
        // Bcc-only message.
        $cc  = $this->addresses($email->getCc());
        $bcc = $this->addresses($email->getBcc());
        $to  = [];

        foreach ($sent->getEnvelope()->getRecipients() as $address) {
            $a = $address->getAddress();

            if (! in_array($a, $cc, true) && ! in_array($a, $bcc, true)) {
                $to[] = $a;
            }
        }

        if (! $to && ! $cc && ! $bcc) {
            throw new TransportException('Swoop was given a message with no recipients.');
        }

        $failure = $this->client->deliver([
            'to'       => implode(',', $to),
            'cc'       => implode(',', $cc),
            'bcc'      => implode(',', $bcc),
            'subject'  => (string) $email->getSubject(),
            // Not cast: getHtmlBody() can hand back a resource, and casting one
            // yields the string "Resource id #4" — silently, into somebody's
            // inbox.
            'html'     => $this->body($email->getHtmlBody()),
            'text'     => $this->body($email->getTextBody()),
            'reply_to' => implode(',', $this->addresses($email->getReplyTo())),
            'from'     => $email->getFrom() ? $email->getFrom()[0]->getAddress() : '',
            // Everything the forum set that Postmark does not take as a field
            // of its own. List-Unsubscribe lives here, and Gmail and Yahoo
            // require it from anything sending at volume — which is exactly
            // what a driver carrying notifications is.
            'headers'  => $this->extraHeaders($email),
        ]);

        if ($failure !== null) {
            // Thrown, not swallowed. Flarum's Mailer logs it, dispatches
            // EmailSendFailed and rethrows; on a stock forum the queue driver
            // is `sync`, so this surfaces in the request that triggered the
            // mail rather than being retried. Same as a dead SMTP host.
            throw new TransportException('Swoop refused the message: '.$failure);
        }
    }

    /** @param Address[] $addresses @return string[] */
    private function addresses(array $addresses): array
    {
        return array_map(fn (Address $a) => $a->getAddress(), $addresses);
    }

    /** @return array<string,string> */
    private function extraHeaders(Email $email): array
    {
        $out = [];

        foreach ($email->getHeaders()->all() as $header) {
            if (in_array(strtolower($header->getName()), self::OWN_FIELDS, true)) {
                continue;
            }

            $out[$header->getName()] = $header->getBodyAsString();
        }

        return $out;
    }

    private function body(mixed $body): string
    {
        if (is_resource($body)) {
            return (string) stream_get_contents($body);
        }

        return $body === null ? '' : (string) $body;
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
