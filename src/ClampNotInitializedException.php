<?php

declare(strict_types=1);

namespace Clamp\Analytics;

/**
 * Raised when Analytics::track() is called before Analytics::init().
 */
final class ClampNotInitializedException extends ClampException
{
}
