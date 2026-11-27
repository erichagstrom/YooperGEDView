<?php

declare(strict_types=1);

namespace YooperGEDView\Console;

use RuntimeException;

/**
 * A command could not do what was asked. The message is for the person at the keyboard.
 */
final class CommandFailed extends RuntimeException {}
