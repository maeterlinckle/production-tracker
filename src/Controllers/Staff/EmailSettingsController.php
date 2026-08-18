<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Mail\Mailer;
use App\Models\EmailLog;
use App\Models\Setting;

final class EmailSettingsController
{
    public function index(): void
    {
        Auth::authorize('manage_settings');

        View::render('staff/settings/email', [
            'title' => 'Email settings',
            'enabled' => Mailer::isEnabled(),
            'host' => Mailer::setting('host', 'host'),
            'port' => Mailer::setting('port', 'port', '587'),
            'encryption' => Mailer::setting('encryption', 'encryption', 'tls'),
            'username' => Mailer::setting('username', 'username'),
            'fromAddress' => Mailer::setting('from_address', 'from_address'),
            'fromName' => Mailer::setting('from_name', 'from_name', 'Production Tracker'),
            'passwordSource' => Mailer::passwordSource(),
            'problems' => Mailer::problems(),
            'cryptoOk' => Crypto::isAvailable() && Crypto::hasKey(),
            'encryptions' => Mailer::ENCRYPTIONS,
            'recentLog' => EmailLog::recent(10),
        ]);
    }

    public function update(): void
    {
        Auth::authorize('manage_settings');

        $data = [
            'host' => trim((string) Request::post('host', '')),
            'port' => Request::post('port', '587'),
            'encryption' => Request::post('encryption', 'tls'),
            'username' => trim((string) Request::post('username', '')),
            'from_address' => trim((string) Request::post('from_address', '')),
            'from_name' => trim((string) Request::post('from_name', '')),
        ];

        $validator = new Validator($data);
        $validator->integerMin('port', 'Port', 1)->maxLength('port', 'Port', 5);
        if ($data['from_address'] !== '') {
            $validator->email('from_address', '"From" address');
        }
        if (!in_array($data['encryption'], ['tls', 'ssl', 'none'], true)) {
            $data['encryption'] = 'tls';
        }

        $enabled = Request::boolean('mail_enabled');
        if ($enabled) {
            if ($data['host'] === '') {
                Flash::error('An SMTP host is needed before email can be switched on.');
                Response::redirect('/staff/settings/email');
            }
            if ($data['from_address'] === '') {
                Flash::error('A "from" address is needed before email can be switched on.');
                Response::redirect('/staff/settings/email');
            }
        }

        if ($validator->fails()) {
            Flash::error(implode(' ', $validator->errors()));
            Response::redirect('/staff/settings/email');
        }

        Setting::put('mail_enabled', $enabled ? '1' : '0');
        Setting::put('mail_host', $data['host']);
        Setting::put('mail_port', (string) (int) $data['port']);
        Setting::put('mail_encryption', $data['encryption']);
        Setting::put('mail_username', $data['username']);
        Setting::put('mail_from_address', $data['from_address']);
        Setting::put('mail_from_name', $data['from_name']);

        if (Request::boolean('mail_password_clear')) {
            Mailer::storePassword('');
        } else {
            $password = (string) Request::post('password', '');
            if ($password !== '' && !Mailer::storePassword($password)) {
                Flash::error(Crypto::isAvailable()
                    ? 'The password could not be encrypted because APP_KEY is not set in .env. Generate one with "php bin/console.php key:generate", then try again.'
                    : 'The password could not be encrypted because the PHP openssl extension is not loaded.');
                Response::redirect('/staff/settings/email');
            }
        }

        Flash::success('Email settings saved.' . ($enabled ? ' Send yourself a test message to prove the connection.' : ''));
        Response::redirect('/staff/settings/email');
    }

    public function test(): void
    {
        Auth::authorize('manage_settings');

        $email = (string) Request::post('test_email', '');
        $validator = new Validator(['test_email' => $email]);
        $validator->required('test_email', 'Test address')->email('test_email', 'Test address');

        if ($validator->fails()) {
            Flash::error(implode(' ', $validator->errors()));
            Response::redirect('/staff/settings/email');
        }

        if (!Mailer::isEnabled()) {
            Flash::error('Email sending is switched off. Tick "Send email from this application" and save before testing.');
            Response::redirect('/staff/settings/email');
        }

        $user = Auth::user();
        $sent = Mailer::sendTemplate(
            'smtp_test',
            $email,
            (string) ($user['name'] ?? ''),
            [
                'mail_host' => Mailer::setting('host', 'host'),
                'recipient' => $email,
                'sent_at'   => date('j M Y, H:i'),
                'sent_by'   => (string) ($user['name'] ?? 'a staff member'),
            ]
        );

        if ($sent) {
            Flash::success('Test message sent to ' . $email . '. If it does not arrive, check the spam folder, then the log below.');
        } else {
            $latest = EmailLog::recent(1);
            Flash::error('The test message could not be sent: ' . ($latest[0]['error'] ?? 'see the log below.'));
        }

        Response::redirect('/staff/settings/email');
    }
}
