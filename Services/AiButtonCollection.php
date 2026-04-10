<?php

declare(strict_types=1);

/**
 * Typed collection of AiButton objects.
 *
 * Implements Countable + IteratorAggregate so you can use count() and foreach
 * directly on the collection.
 *
 * Factory method:
 *   AiButtonCollection::fromJson(string $json): self
 */
class AiButtonCollection implements Countable, IteratorAggregate
{
    /** @var AiButton[] */
    private $buttons;

    /**
     * @param AiButton[] $buttons
     */
    public function __construct(array $buttons = [])
    {
        // Re-index to guarantee sequential 0-based keys
        $this->buttons = array_values($buttons);
    }

    /**
     * Build a collection from a JSON string (as stored in FreshRSS config).
     * Returns an empty collection on parse error or empty input.
     */
    public static function fromJson(string $json): self
    {
        if (trim($json) === '') {
            return new self();
        }

        $arr = json_decode($json, true);
        if (!is_array($arr)) {
            return new self();
        }

        $buttons = [];
        foreach ($arr as $item) {
            if (!is_array($item)) {
                continue;
            }
            $btn = AiButton::fromArray($item);
            if ($btn !== null) {
                $buttons[] = $btn;
            }
        }

        return new self($buttons);
    }

    /**
     * Get a button by index (0-based).
     * Returns null when the index is out of range.
     */
    public function get(int $index): ?AiButton
    {
        return $this->buttons[$index] ?? null;
    }

    /**
     * Return all buttons as a plain array.
     *
     * @return AiButton[]
     */
    public function all(): array
    {
        return $this->buttons;
    }

    public function isEmpty(): bool
    {
        return empty($this->buttons);
    }

    // ── Countable ────────────────────────────────────────────────────────────

    public function count(): int
    {
        return count($this->buttons);
    }

    // ── IteratorAggregate ────────────────────────────────────────────────────

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->buttons);
    }
}

