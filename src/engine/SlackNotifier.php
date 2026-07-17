<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

final class SlackNotifier implements Notifier
{
    public function __construct(
        private readonly HttpPoster $http,
        private readonly string $webhookUrl,
    ) {
    }

    public function name(): string
    {
        return 'notify:slack';
    }

    public function notify(RunSummary $s): void
    {
        $site = self::escape($s->site());
        // Rewrites the 'Backup ...' messages produced by BackupRunner / NotifyController into Ploi-style prose; anything else falls back verbatim.
        $text = str_starts_with($s->message, 'Backup ')
            ? 'Backup of *' . $site . '* ' . self::escape(substr($s->message, strlen('Backup ')))
            : '*' . $site . '* — ' . self::escape($s->message);
        $fields = [
            ['title' => 'Site', 'value' => $site, 'short' => true],
            ['title' => 'Run', 'value' => '`' . self::escape($s->runId) . '`', 'short' => true],
        ];
        foreach ($s->details as $k => $v) {
            if ($k === 'site' || $k === 'status') {
                continue;
            }
            $fields[] = ['title' => ucfirst($k), 'value' => self::escape($v), 'short' => true];
        }
        $this->http->post($this->webhookUrl, json_encode([
            'text' => $text,
            'attachments' => [[
                'fallback' => str_replace('*', '', $text) . ' (' . self::escape($s->runId) . ')',
                'color' => $s->ok ? '#2eb67d' : '#e01e5a',
                'mrkdwn_in' => ['text', 'fields'],
                'fields' => $fields,
            ]],
        ]));
    }

    private static function escape(string $v): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $v);
    }
}
