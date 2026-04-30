# clamp/analytics (PHP) changelog

## 0.1.0

Initial release.

- `Analytics::init(projectId, apiKey, endpoint = null)`: configure the SDK once at application boot.
- `Analytics::track(name, properties = [], anonymousId = null, timestamp = null)`: send a server event. Returns `true`; throws `ClampHttpException` on non-2xx, `ClampNotInitializedException` when called before init.
- `Money(amount, currency)`: typed monetary value for revenue, refunds, taxes.
- Property values: `string`, `int`, `float`, `bool`, `Money`. Arrays and plain objects rejected at call time.
- No external dependencies. Uses `ext-curl` and `ext-json` (both standard).
- Tested on PHP 8.1 through 8.4.
