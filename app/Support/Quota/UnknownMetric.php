<?php

declare(strict_types=1);

namespace App\Support\Quota;

use InvalidArgumentException;

/**
 * A metric key nobody registered.
 *
 * Always a programming error — a typo in a `consume()` call, or a module that meters
 * something it forgot to declare. It throws rather than degrading to "unlimited", because
 * a mistyped key that silently meters nothing is a quota that never fires and a bug that
 * shows up as missing revenue months later.
 */
final class UnknownMetric extends InvalidArgumentException {}
