<?php

declare(strict_types=1);

namespace Clamp\Analytics\Tests;

use Clamp\Analytics\Analytics;
use Clamp\Analytics\ClampHttpException;
use Clamp\Analytics\ClampNotInitializedException;
use Clamp\Analytics\Money;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class AnalyticsTest extends TestCase
{
    /**
     * @var array<int, array{url: string, headers: array<string, string>, body: string}>
     */
    private array $captured = [];

    protected function setUp(): void
    {
        Analytics::reset();
        $this->captured = [];
    }

    private function captureTransport(int $status = 200, string $body = ''): void
    {
        Analytics::setTransport(function (string $url, array $headers, string $payload) use (&$status, &$body): array {
            $this->captured[] = ['url' => $url, 'headers' => $headers, 'body' => $payload];
            return ['status' => $status, 'body' => $body];
        });
    }

    public function testInitThenTrackHappyPath(): void
    {
        $this->captureTransport();
        Analytics::init('proj_test', 'sk_proj_test');

        $result = Analytics::track('signup', ['plan' => 'pro']);
        $this->assertTrue($result);

        $this->assertCount(1, $this->captured);
        $request = $this->captured[0];
        $this->assertSame('https://api.clamp.sh/e/s', $request['url']);
        $this->assertSame('application/json', $request['headers']['content-type']);
        $this->assertSame('sk_proj_test', $request['headers']['x-clamp-key']);

        $payload = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('proj_test', $payload['p']);
        $this->assertSame('signup', $payload['name']);
        $this->assertSame(['plan' => 'pro'], $payload['properties']);
    }

    public function testTrackWithoutInitRaises(): void
    {
        $this->expectException(ClampNotInitializedException::class);
        Analytics::track('signup');
    }

    public function testPropertyValueTypesRoundtrip(): void
    {
        $this->captureTransport();
        Analytics::init('proj_test', 'sk_proj_test');

        Analytics::track('purchase', [
            'plan' => 'pro',
            'items' => 3,
            'discount' => 0.15,
            'refunded' => false,
            'total' => new Money(29.00, 'USD'),
        ]);

        $payload = json_decode($this->captured[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        $props = $payload['properties'];
        $this->assertSame('pro', $props['plan']);
        $this->assertSame(3, $props['items']);
        $this->assertSame(0.15, $props['discount']);
        $this->assertFalse($props['refunded']);
        $this->assertSame(['amount' => 29.00, 'currency' => 'USD'], $props['total']);
    }

    public function testUnsupportedPropertyTypeRaises(): void
    {
        Analytics::init('proj_test', 'sk_proj_test');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unsupported type/');
        // @phpstan-ignore-next-line: deliberately passing an unsupported type
        Analytics::track('event', ['items' => [1, 2, 3]]);
    }

    public function testNon2xxResponseRaises(): void
    {
        $this->captureTransport(401, 'invalid api key');
        Analytics::init('proj_test', 'sk_proj_bad');

        try {
            Analytics::track('signup');
            $this->fail('Expected ClampHttpException');
        } catch (ClampHttpException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertStringContainsString('invalid api key', $e->body);
        }
    }

    public function testEndpointOverride(): void
    {
        $this->captureTransport();
        Analytics::init('proj_test', 'sk_proj_test', 'https://staging.clamp.example');

        Analytics::track('signup');
        $this->assertSame('https://staging.clamp.example/e/s', $this->captured[0]['url']);
    }

    public function testOptionalFieldsPresentWhenProvided(): void
    {
        $this->captureTransport();
        Analytics::init('proj_test', 'sk_proj_test');

        $ts = new DateTimeImmutable('2026-04-29T12:00:00', new DateTimeZone('UTC'));
        Analytics::track('signup', anonymousId: 'aid_xyz', timestamp: $ts);

        $payload = json_decode($this->captured[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('aid_xyz', $payload['anonymousId']);
        $this->assertSame('2026-04-29T12:00:00Z', $payload['timestamp']);
    }

    public function testOptionalFieldsOmittedWhenAbsent(): void
    {
        $this->captureTransport();
        Analytics::init('proj_test', 'sk_proj_test');

        Analytics::track('signup');

        $payload = json_decode($this->captured[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('anonymousId', $payload);
        $this->assertArrayNotHasKey('properties', $payload);
        $this->assertArrayHasKey('timestamp', $payload);
    }

    public function testStringTimestampPassedThrough(): void
    {
        $this->captureTransport();
        Analytics::init('proj_test', 'sk_proj_test');

        Analytics::track('signup', timestamp: '2026-04-29T12:00:00Z');

        $payload = json_decode($this->captured[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('2026-04-29T12:00:00Z', $payload['timestamp']);
    }

    public function testNonUtcDatetimeNormalizedToUtc(): void
    {
        $this->captureTransport();
        Analytics::init('proj_test', 'sk_proj_test');

        $ts = new DateTimeImmutable('2026-04-29T14:00:00', new DateTimeZone('Europe/Berlin'));
        Analytics::track('signup', timestamp: $ts);

        $payload = json_decode($this->captured[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringEndsWith('Z', $payload['timestamp']);
        // Berlin in April is UTC+2; 14:00 local is 12:00 UTC.
        $this->assertSame('2026-04-29T12:00:00Z', $payload['timestamp']);
    }
}
