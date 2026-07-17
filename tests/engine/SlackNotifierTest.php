<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\engine;

use cdgrph\offsite\engine\RunSummary;
use cdgrph\offsite\engine\SlackNotifier;
use cdgrph\offsite\tests\support\FakeHttpPoster;
use PHPUnit\Framework\TestCase;

final class SlackNotifierTest extends TestCase
{
    public function testPostsFailurePayloadWithDetails(): void
    {
        $http = new FakeHttpPoster();
        $n = new SlackNotifier($http, 'https://hooks.slack.com/services/T/B/x');
        $n->notify(new RunSummary('run-1', false, 'Backup failed: disk full', ['site' => 'example.com', 'status' => 'failed']));

        self::assertCount(1, $http->calls);
        $payload = json_decode($http->calls[0]['body'], true);
        self::assertSame('#e01e5a', $payload['attachments'][0]['color']);
        self::assertSame('Backup of *example.com* failed: disk full', $payload['text']);
        $fields = $payload['attachments'][0]['fields'];
        self::assertSame(['Site', 'Run'], array_column($fields, 'title'));
        self::assertSame('example.com', self::fieldByTitle($fields, 'Site')['value']);
        self::assertSame('`run-1`', self::fieldByTitle($fields, 'Run')['value']);
        self::assertNull(self::fieldByTitle($fields, 'Size'));
        self::assertContains('fields', $payload['attachments'][0]['mrkdwn_in']);
    }

    public function testSuccessIncludesSiteNameAndSize(): void
    {
        $http = new FakeHttpPoster();
        (new SlackNotifier($http, 'https://hooks.example/x'))
            ->notify(new RunSummary('run-2', true, 'Backup succeeded', ['site' => 'example.com', 'status' => 'committed', 'size' => '3.3 MB', 'duration' => '8s']));
        $payload = json_decode($http->calls[0]['body'], true);
        self::assertSame('#2eb67d', $payload['attachments'][0]['color']);
        self::assertSame('Backup of *example.com* succeeded', $payload['text']);
        self::assertSame('Backup of example.com succeeded (run-2)', $payload['attachments'][0]['fallback']);
        $fields = $payload['attachments'][0]['fields'];
        self::assertSame(['Site', 'Run', 'Size', 'Duration'], array_column($fields, 'title'));
        self::assertSame('example.com', self::fieldByTitle($fields, 'Site')['value']);
        self::assertSame('`run-2`', self::fieldByTitle($fields, 'Run')['value']);
        self::assertSame('3.3 MB', self::fieldByTitle($fields, 'Size')['value']);
        self::assertSame('8s', self::fieldByTitle($fields, 'Duration')['value']);
        foreach ($fields as $field) {
            self::assertTrue($field['short']);
        }
    }

    public function testResentMessageKeepsSuffix(): void
    {
        $http = new FakeHttpPoster();
        (new SlackNotifier($http, 'https://hooks.example/x'))
            ->notify(new RunSummary('run-9', true, 'Backup succeeded (resent)', ['site' => 'example.com', 'status' => 'committed']));
        $payload = json_decode($http->calls[0]['body'], true);
        self::assertSame('Backup of *example.com* succeeded (resent)', $payload['text']);
    }

    public function testNonBackupMessageFallsBackToDashFormat(): void
    {
        $http = new FakeHttpPoster();
        (new SlackNotifier($http, 'https://hooks.example/x'))
            ->notify(new RunSummary('run-10', true, 'Restore completed', ['site' => 'example.com']));
        $payload = json_decode($http->calls[0]['body'], true);
        self::assertSame('*example.com* — Restore completed', $payload['text']);
    }

    public function testEscapesMrkdwnControlCharacters(): void
    {
        $http = new FakeHttpPoster();
        (new SlackNotifier($http, 'https://hooks.example/x'))
            ->notify(new RunSummary('run-11', false, 'Backup failed: <Error><Code>AccessDenied</Code>', ['site' => 'a&b.example']));
        $payload = json_decode($http->calls[0]['body'], true);
        self::assertSame('Backup of *a&amp;b.example* failed: &lt;Error&gt;&lt;Code&gt;AccessDenied&lt;/Code&gt;', $payload['text']);
        self::assertSame('a&amp;b.example', self::fieldByTitle($payload['attachments'][0]['fields'], 'Site')['value']);
    }

    public function testEmptySiteFallsBackToUnknown(): void
    {
        $http = new FakeHttpPoster();
        (new SlackNotifier($http, 'https://hooks.example/x'))
            ->notify(new RunSummary('run-12', true, 'Backup succeeded', ['site' => '']));
        $payload = json_decode($http->calls[0]['body'], true);
        self::assertSame('Backup of *unknown* succeeded', $payload['text']);
        self::assertSame('unknown', self::fieldByTitle($payload['attachments'][0]['fields'], 'Site')['value']);
    }

    public function testHttpFailurePropagates(): void
    {
        $http = new FakeHttpPoster();
        $http->fail = true;
        $this->expectException(\RuntimeException::class);
        (new SlackNotifier($http, 'https://hooks.example/x'))
            ->notify(new RunSummary('run-3', true, 'ok', []));
    }

    private static function fieldByTitle(array $fields, string $title): ?array
    {
        foreach ($fields as $field) {
            if ($field['title'] === $title) {
                return $field;
            }
        }

        return null;
    }
}
