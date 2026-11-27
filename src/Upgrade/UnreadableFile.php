<?php

declare(strict_types=1);

namespace YooperGEDView\Upgrade;

use RuntimeException;

/**
 * A file from the old site does not hold what it should.
 */
final class UnreadableFile extends RuntimeException {}
