<?php

declare(strict_types=1);

namespace Clamp\Analytics;

use Closure;
use DateTimeInterface;
use DateTimeZone;
use DateTimeImmutable;

/**
 * Server-side analytics SDK for Clamp.
 *
 *     use Clamp\Analytics\Analytics;
 *     use Clamp\Analytics\Money;
 *
 *     Analytics::init('proj_xxx', 'sk_proj_xxx');
 *
 *     Analytics::track('signup', ['plan' => 'pro', 'method' => 'email']);
 *
 *     Analytics::track('subscription_started', [
 *         'plan' => 'pro',
 *         'total' => new Money(29.00, 'USD'),
 *     ], anonymousId: 'aid_xxx');
 */
final class Analytics
{
    public const DEFAULT_ENDPOINT = 'https://api.clamp.sh';

    private static ?string $projectId = null;
    private static ?string $apiKey = null;
    private static string $endpoint = self::DEFAULT_ENDPOINT;

    /**
     * Transport callable: function(string $url, array $headers, string $body): array{status: int, body: string}.
     * Defaulted to a curl-backed implementation; tests override.
     *
     * @var Closure(string, array<string, string>, string): array{status: int, body: string}|null
     */
    private static ?Closure $transport = null;

    /**
     * Initialize the SDK. Call once at application bootstrap (e.g. Laravel's
     * AppServiceProvider, Symfony's compiler pass, or WordPress's plugins_loaded).
     */
    public static function init(string $projectId, string $apiKey, ?string $endpoint = null): void
    {
        self::$projectId = $projectId;
        self::$apiKey = $apiKey;
        self::$endpoint = $endpoint ?? self::DEFAULT_ENDPOINT;
    }

    /**
     * Track a server-side event.
     *
     * @param array<string, string|int|float|bool|Money> $properties
     * @throws ClampNotInitializedException If init() has not been called.
     * @throws ClampHttpException If the API returns a non-2xx response.
     */
    public static function track(
        string $name,
        array $properties = [],
        ?string $anonymousId = null,
        DateTimeInterface|string|null $timestamp = null,
    ): bool {
        if (self::$projectId === null || self::$apiKey === null) {
            throw new ClampNotInitializedException(
                'clamp_analytics: call Analytics::init() before track()'
            );
        }

        $payload = [
            'p' => self::$projectId,
            'name' => $name,
        ];

        if ($anonymousId !== null) {
            $payload['anonymousId'] = $anonymousId;
        }

        if ($properties !== []) {
            $payload['properties'] = self::serializeProperties($properties);
        }

        $payload['timestamp'] = self::serializeTimestamp($timestamp);

        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = (self::transport())(
            self::$endpoint . '/e/s',
            [
                'content-type' => 'application/json',
                'x-clamp-key' => self::$apiKey,
            ],
            $body,
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new ClampHttpException($response['status'], $response['body']);
        }

        return true;
    }

    /**
     * Capture an exception or throwable as a `$error` event. Convenience
     * wrapper that extracts message/type/stack from the throwable and
     * forwards to track(). The server adds a stable fingerprint at ingest
     * so the same bug groups across occurrences.
     *
     *     try {
     *         processWebhook($payload);
     *     } catch (\Throwable $e) {
     *         Analytics::captureError($e, ['webhook' => 'stripe']);
     *     }
     *
     * @param array<string, string|int|float|bool> $context Extra primitive
     *     properties; nested values are dropped. The reserved key 'handled'
     *     is ignored if present.
     */
    public static function captureError(
        \Throwable $exception,
        array $context = [],
        ?string $anonymousId = null,
        DateTimeInterface|string|null $timestamp = null,
    ): bool {
        $message = substr($exception->getMessage() ?: 'Unknown error', 0, 1024);
        $type = substr((new \ReflectionClass($exception))->getShortName(), 0, 64);
        $stack = substr(
            $exception->getTraceAsString() ?: $exception->getFile() . ':' . $exception->getLine(),
            0,
            16384,
        );

        /** @var array<string, string|int|float|bool|Money> $properties */
        $properties = [
            'error.message' => $message,
            'error.type' => $type,
            'error.stack' => $stack,
            'error.handled' => true,
        ];
        foreach ($context as $key => $value) {
            if ($key === 'handled') {
                continue;
            }
            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                $properties[$key] = $value;
            }
        }

        return self::track('$error', $properties, $anonymousId, $timestamp);
    }

    /**
     * Override the transport (used by tests). Pass null to restore the default.
     *
     * @param Closure(string, array<string, string>, string): array{status: int, body: string}|null $transport
     */
    public static function setTransport(?Closure $transport): void
    {
        self::$transport = $transport;
    }

    /**
     * Reset all state. Intended for tests.
     */
    public static function reset(): void
    {
        self::$projectId = null;
        self::$apiKey = null;
        self::$endpoint = self::DEFAULT_ENDPOINT;
        self::$transport = null;
    }

    /**
     * @return Closure(string, array<string, string>, string): array{status: int, body: string}
     */
    private static function transport(): Closure
    {
        return self::$transport ?? self::defaultTransport();
    }

    /**
     * @return Closure(string, array<string, string>, string): array{status: int, body: string}
     */
    private static function defaultTransport(): Closure
    {
        return static function (string $url, array $headers, string $body): array {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new ClampException('clamp_analytics: failed to init curl handle');
            }

            $curlHeaders = [];
            foreach ($headers as $key => $value) {
                $curlHeaders[] = "{$key}: {$value}";
            }

            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new ClampException("clamp_analytics: curl error: {$error}");
            }

            return [
                'status' => $status,
                'body' => is_string($response) ? $response : '',
            ];
        };
    }

    /**
     * @param array<string, string|int|float|bool|Money> $properties
     * @return array<string, string|int|float|bool|array{amount: float, currency: string}>
     */
    private static function serializeProperties(array $properties): array
    {
        $out = [];
        foreach ($properties as $key => $value) {
            if ($value instanceof Money) {
                $out[$key] = $value->toWire();
            } elseif (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                $out[$key] = $value;
            } else {
                $type = is_object($value) ? $value::class : gettype($value);
                throw new \InvalidArgumentException(
                    "clamp_analytics: property '{$key}' has unsupported type {$type}. "
                    . 'Allowed: string, int, float, bool, Money.'
                );
            }
        }
        return $out;
    }

    private static function serializeTimestamp(DateTimeInterface|string|null $timestamp): string
    {
        if ($timestamp === null) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            return self::formatIso8601($now);
        }
        if ($timestamp instanceof DateTimeInterface) {
            $tz = $timestamp->getTimezone();
            $name = $tz->getName();
            if ($name !== 'UTC' && $name !== 'Z' && $name !== '+00:00') {
                $timestamp = (new DateTimeImmutable('@' . $timestamp->getTimestamp()))
                    ->setTimezone(new DateTimeZone('UTC'));
            }
            return self::formatIso8601($timestamp);
        }
        return $timestamp;
    }

    private static function formatIso8601(DateTimeInterface $dt): string
    {
        return $dt->format('Y-m-d\TH:i:s\Z');
    }
}
