<?php
declare(strict_types=1);

namespace cdgrph\offsite\adapters;

use cdgrph\offsite\engine\Notifier;
use cdgrph\offsite\engine\RunSummary;
use craft\mail\Message;

final class CraftMailNotifier implements Notifier
{
    public function __construct(private readonly string $to)
    {
    }

    public function name(): string
    {
        return 'notify:email';
    }

    public function notify(RunSummary $s): void
    {
        $site = $s->site();
        $icon = $s->ok ? "\u{2705}" : "\u{274C}";
        $subject = "{$icon} [Offsite] {$site} — {$s->message}";
        $lines = ["Run:      {$s->runId}"];
        foreach ($s->details as $k => $v) {
            $label = str_pad(ucfirst($k) . ':', 10);
            $lines[] = "{$label}{$v}";
        }
        $body = implode("\n", $lines);
        $message = (new Message())->setTo($this->to)->setSubject($subject)->setTextBody($body);
        if (!\Craft::$app->getMailer()->send($message)) {
            throw new \RuntimeException('Craft mailer returned false.');
        }
    }
}
