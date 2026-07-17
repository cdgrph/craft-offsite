<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\engine;

use cdgrph\offsite\engine\HeartbeatPinger;
use cdgrph\offsite\tests\support\FakeHttpPoster;
use PHPUnit\Framework\TestCase;

final class HeartbeatPingerTest extends TestCase
{
    public function testSuccessPingsBaseUrl(): void
    {
        $http = new FakeHttpPoster();
        (new HeartbeatPinger($http, 'https://hc-ping.com/uuid'))->success();
        self::assertSame('https://hc-ping.com/uuid', $http->calls[0]['url']);
    }

    public function testFailurePingsFailEndpoint(): void
    {
        $http = new FakeHttpPoster();
        (new HeartbeatPinger($http, 'https://hc-ping.com/uuid/'))->failure();
        self::assertSame('https://hc-ping.com/uuid/fail', $http->calls[0]['url']);
    }
}
