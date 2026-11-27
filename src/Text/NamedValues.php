<?php

declare(strict_types=1);

namespace YooperGEDView\Text;

/**
 * Looks up a value by a reference written in a text or a report file: "name", or "name['key']" for one entry
 * of a list. Only the names handed over when this is made can be reached. The inherited code ran the
 * reference as PHP instead, which let a text name any variable in the program, the database password included.
 */
final readonly class NamedValues
{
    private const string REFERENCE_PATTERN = '/^(?<name>\w+)(?:\[\'(?<key>[^\'\]]*)\'\])?$/';

    /**
     * @param array<string, mixed> $values what may be looked up: single values, and lists of them
     */
    public function __construct(private array $values) {}

    /**
     * The value referred to; null when the reference is not one of the two forms, names something that was
     * not handed over, or leads to anything but a single value.
     */
    public function find(string $reference): string|int|float|bool|null
    {
        if (preg_match(self::REFERENCE_PATTERN, $reference, $match) !== 1 || !array_key_exists($match['name'], $this->values)) {
            return null;
        }
        $value = $this->values[$match['name']];
        if (isset($match['key'])) {
            $value = is_array($value) && array_key_exists($match['key'], $value) ? $value[$match['key']] : null;
        }

        return is_scalar($value) ? $value : null;
    }
}
