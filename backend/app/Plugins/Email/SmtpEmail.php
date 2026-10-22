<?php

namespace App\Plugins\Email;

use App\Enums\GatewayType;
use App\Plugins\AbstractPlugin;
use App\Plugins\Contracts\EmailPlugin;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class SmtpEmail extends AbstractPlugin implements EmailPlugin
{
    public static function key(): string { return 'smtp'; }

    public static function name(): string { return 'SMTP'; }

    public static function type(): GatewayType { return GatewayType::Email; }

    public static function description(): string { return 'Any SMTP server (Microsoft 365, Google Workspace, Zoho, Mailgun, SES…).'; }

    public static function configSchema(): array
    {
        return [
            ['key' => 'host', 'label' => 'SMTP host', 'type' => 'string', 'required' => true],
            ['key' => 'port', 'label' => 'Port', 'type' => 'number', 'default' => 587],
            ['key' => 'encryption', 'label' => 'Encryption', 'type' => 'select', 'options' => ['tls', 'ssl', 'none'], 'default' => 'tls'],
            ['key' => 'username', 'label' => 'Username', 'type' => 'string'],
            ['key' => 'password', 'label' => 'Password', 'type' => 'secret'],
            ['key' => 'from_address', 'label' => 'From address', 'type' => 'email', 'required' => true],
            ['key' => 'from_name', 'label' => 'From name', 'type' => 'string', 'default' => 'RideCoop'],
        ];
    }

    public function send(string $to, string $subject, string $html, ?string $text = null): void
    {
        $email = (new Email)
            ->from(new Address($this->cfg('from_address'), (string) $this->cfg('from_name', '')))
            ->to($to)
            ->subject($subject)
            ->html($html)
            ->text($text ?? trim(html_entity_decode(strip_tags($html))));

        (new Mailer(Transport::fromDsn($this->dsn())))->send($email);
    }

    public function test(array $context = []): array
    {
        try {
            if (! empty($context['to'])) {
                $this->send($context['to'], 'RideCoop test email', '<p>Your SMTP gateway is working.</p>');

                return ['ok' => true, 'message' => "Sent to {$context['to']}"];
            }
            $transport = Transport::fromDsn($this->dsn());
            if (method_exists($transport, 'start')) {
                $transport->start();
                $transport->stop();
            }

            return ['ok' => true, 'message' => 'Connected and authenticated.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function dsn(): string
    {
        $scheme = $this->cfg('encryption') === 'ssl' ? 'smtps' : 'smtp';
        $auth = $this->cfg('username') ? rawurlencode((string) $this->cfg('username')).':'.rawurlencode((string) $this->cfg('password', '')).'@' : '';
        $query = $this->cfg('encryption') === 'none' ? '?auto_tls=false' : '';

        return sprintf('%s://%s%s:%d%s', $scheme, $auth, $this->cfg('host'), (int) $this->cfg('port', 587), $query);
    }
}
