<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Mail\EmailTemplate;
use App\Mail\Mailer;
use App\Mail\Merge;

/**
 * The template editor.
 *
 * Everything an administrator can get wrong here is caught before it is saved:
 * an empty subject, a placeholder the sending code does not supply, or turning
 * off a message that carries a single-use link. The preview renders the wording
 * against the template's own sample values, so it can be judged without waiting
 * for a real order to reach the right state.
 */
final class EmailTemplateController
{
    public function index(): void
    {
        Auth::authorize('manage_settings');

        $grouped = [];
        foreach (EmailTemplate::all() as $template) {
            $grouped[$template['group']][] = $template;
        }

        View::render('staff/settings/email-templates', [
            'title' => 'Email templates',
            'grouped' => $grouped,
            'customisedCount' => EmailTemplate::customisedCount(),
        ]);
    }

    public function edit(string $key): void
    {
        Auth::authorize('manage_settings');

        $template = EmailTemplate::find($key);
        if ($template === null) {
            View::renderError(404, 'Template not found', 'There is no email template with that name.');

            return;
        }

        View::render('staff/settings/email-template-form', [
            'title' => $template['name'],
            'template' => $template,
            'preview' => self::preview($template, (string) $template['subject'], (string) $template['body']),
        ]);
    }

    public function update(string $key): void
    {
        Auth::authorize('manage_settings');

        $template = EmailTemplate::find($key);
        if ($template === null) {
            View::renderError(404, 'Template not found', 'There is no email template with that name.');

            return;
        }

        $subject = trim((string) Request::post('subject', ''));
        $body    = trim((string) Request::post('body', ''));
        $isHtml  = Request::boolean('is_html');

        // A locked template's "active" checkbox is not rendered at all, so an
        // absent value must not be read as "switch it off".
        $isActive = $template['can_be_disabled'] ? Request::boolean('is_active') : true;

        if ($subject === '' || $body === '') {
            Flash::error('A template needs both a subject and a body.');
            Response::redirect('/staff/settings/email/templates/' . $key);
        }

        $unknown = array_unique(array_merge(
            Merge::unknown($subject, $template['fields']),
            Merge::unknown($body, $template['fields'])
        ));

        if ($unknown !== []) {
            Flash::error('These placeholders are not supplied for this message, so they would come out blank: '
                . implode(', ', array_map(static fn (string $f): string => '{{' . $f . '}}', $unknown))
                . '. Check the list of merge fields below the form.');
            Response::redirect('/staff/settings/email/templates/' . $key);
        }

        EmailTemplate::save($key, $subject, $body, $isHtml, $isActive);

        Flash::success('“' . $template['name'] . '” saved.');
        Response::redirect('/staff/settings/email/templates/' . $key);
    }

    public function reset(string $key): void
    {
        Auth::authorize('manage_settings');

        $template = EmailTemplate::find($key);
        if ($template === null) {
            View::renderError(404, 'Template not found', 'There is no email template with that name.');

            return;
        }

        EmailTemplate::reset($key);

        Flash::success('“' . $template['name'] . '” is back to the built-in wording.');
        Response::redirect('/staff/settings/email/templates/' . $key);
    }

    /**
     * The message as it would be sent, using the template's sample values.
     *
     * Rendered to text rather than shown as a live HTML fragment: the point of
     * the preview is to check the *wording* reads properly with real values in
     * it, and dropping author-written markup into this page would put the
     * message's styling and the application's in the same document.
     *
     * @param array<string,mixed> $template
     * @return array{subject:string,body:string}
     */
    private static function preview(array $template, string $subject, string $body): array
    {
        $fields = array_merge(Mailer::commonFields(Auth::name()), $template['sample']);

        $isHtml        = (bool) $template['is_html'];
        $mergedSubject = Merge::render($subject, $fields);
        $mergedBody    = Merge::render($body, $fields, $isHtml);

        return [
            'subject' => $mergedSubject,
            'body'    => $isHtml ? Merge::htmlToText($mergedBody) : $mergedBody,
        ];
    }
}
