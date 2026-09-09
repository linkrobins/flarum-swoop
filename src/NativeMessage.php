<?php

namespace LinkRobins\Swoop;

use Flarum\Locale\TranslatorInterface;
use Flarum\Mail\SafeSubstitution;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\View\Factory;

/**
 * Renders exactly the email Flarum would have sent.
 *
 * Swoop delivers mail; it does not write it. Everything a member reads —
 * wording, the forum's name, the greeting and footer, and above all the
 * language — comes from the forum's own templates and translations, rendered
 * here with the same views and the same data core's own job uses.
 *
 * The language is the part that cannot be faked from our side. Core picks the
 * *recipient's* preferred locale, so a German member on an English forum gets
 * German. A service rendering its own templates would have to carry every
 * string in every language to match that, and would still be a step behind
 * core's.
 */
class NativeMessage
{
    public function __construct(
        private Factory $view,
        private TranslatorInterface $translator,
        private SettingsRepositoryInterface $settings
    ) {
    }

    /**
     * @param array<string,mixed> $data The same data core passes its templates.
     * @return array{subject:string,html:string,text:string}
     */
    public function render(
        string $subjectKey,
        string $bodyKey,
        array $data,
        string $email,
        string $displayName,
        ?string $locale = null
    ): array {
        $previous = $this->translator->getLocale();
        $locale ??= (string) $this->settings->get('default_locale');

        try {
            if ($locale !== '') {
                $this->translator->setLocale($locale);
            }

            $body    = $this->translator->trans($bodyKey, $data);
            $subject = $this->translator->trans($subjectKey);

            // Shared the way core's job shares it: the wrappers read these off
            // the view factory rather than taking them as data.
            $this->view->share([
                'forumTitle' => (string) $this->settings->get('forum_title'),
                'userEmail'  => $email,
                'title'      => null,
                'username'   => $displayName,
            ]);

            // Rendered inside the locale block on purpose — the footer and the
            // greeting are translated too.
            $html = $this->view->make('mail::html.information.generic', ['infoContent' => $body])->render();
            $text = $this->view->make('mail::plain.information.generic', ['infoContent' => $body])->render();

            // Core substitutes values with opaque markers before rendering so a
            // username cannot close a markdown link and choose its own target,
            // then puts them back on the finished message -- in a mailer event
            // we never reach, because we are not going through the mailer.
            // Without this a member reads
            // "Hey flarumsafevalue71616e...endflarumsafevalue,".
            // Escaped in HTML, raw in text, exactly as core does it.
            $html = SafeSubstitution::restore($html);
            $text = SafeSubstitution::restore($text, escape: false);

            // The subject never goes through markup, but it is built from the
            // same marked parameters, so it needs putting back too.
            $subject = SafeSubstitution::restore($subject, escape: false);
        } finally {
            $this->translator->setLocale($previous);
        }

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }
}
