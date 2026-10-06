<?php

declare(strict_types=1);

namespace Accord\Core;

/**
 * Hybrid logical clock: physical time + logical counter + node id.
 *
 * Orders events consistently even when device clocks are wrong; `compare` is a total order, since
 * the node id breaks ties. Port of `hlc.ts`.
 */
final readonly class Hlc
{
    /** The largest counter value; one more rolls the clock into the next millisecond. */
    public const int MAX_COUNTER = 99999;

    private const string NODE = '/^[A-Za-z0-9_-]{1,64}$/D';

    /**
     * @param int $wall milliseconds since the Unix epoch, as seen by the node (possibly pushed
     *                  forward by others)
     * @param int $counter disambiguates events within the same wall millisecond
     * @param string $node the device or server that produced the clock
     */
    public function __construct(
        public int $wall,
        public int $counter,
        public string $node,
    ) {}

    /** The clock a node starts from. */
    public static function initial(string $node): self
    {
        self::assertNode($node);

        return new self(0, 0, $node);
    }

    /** Parses `wall:counter:node` (counter zero-padded to 5 digits). */
    public static function decode(string $s): self
    {
        if (preg_match('/^([0-9]{1,16}):([0-9]{5}):([A-Za-z0-9_-]{1,64})$/D', $s, $m) !== 1) {
            throw new AccordException("malformed hlc \"$s\"");
        }
        $wall = (int) $m[1];
        if ($wall > Json::MAX_SAFE_INTEGER) {
            throw new AccordException("hlc wall out of range in \"$s\"");
        }

        return new self($wall, (int) $m[2], $m[3]);
    }

    /** `wall:counter:node`, with the counter zero-padded to 5 digits. */
    public function encode(): string
    {
        return \sprintf('%d:%05d:%s', $this->wall, $this->counter, $this->node);
    }

    /** The clock for a new local event at physical time `$now`. */
    public function tick(int $now): self
    {
        return $now > $this->wall ? new self($now, 0, $this->node) : self::after($this->wall, $this->counter, $this->node);
    }

    /**
     * The clock after observing `$remote` at physical time `$now`. Refuses a remote clock more than
     * `$maxSkewMs` ahead of `$now`, so one phone with a wrong date cannot win every merge forever.
     */
    public function receive(self $remote, int $now, int $maxSkewMs): self
    {
        if ($remote->wall - $now > $maxSkewMs) {
            $ahead = $remote->wall - $now;
            throw new ClockSkewException("clock of {$remote->node} is $ahead ms ahead (limit $maxSkewMs ms)");
        }
        $wall = max($this->wall, $remote->wall, $now);
        if ($wall === $this->wall && $wall === $remote->wall) {
            return self::after($wall, max($this->counter, $remote->counter), $this->node);
        }
        if ($wall === $this->wall) {
            return self::after($wall, $this->counter, $this->node);
        }
        if ($wall === $remote->wall) {
            return self::after($wall, $remote->counter, $this->node);
        }

        return new self($wall, 0, $this->node);
    }

    /** -1, 0 or 1. Node ids are ASCII, so byte order is code unit order. */
    public static function compare(self $a, self $b): int
    {
        return [$a->wall, $a->counter] <=> [$b->wall, $b->counter] ?: ($a->node <=> $b->node);
    }

    public function equals(self $other): bool
    {
        return $this->wall === $other->wall && $this->counter === $other->counter && $this->node === $other->node;
    }

    /** Throws unless `$node` is a valid node (device) id: `[A-Za-z0-9_-]{1,64}`. */
    public static function assertNode(string $node): void
    {
        if (preg_match(self::NODE, $node) !== 1) {
            throw new AccordException("node id must match [A-Za-z0-9_-]{1,64}, got \"$node\"");
        }
    }

    /** The smallest clock after (wall, counter): a full counter rolls into the next millisecond. */
    private static function after(int $wall, int $counter, string $node): self
    {
        return $counter >= self::MAX_COUNTER ? new self($wall + 1, 0, $node) : new self($wall, $counter + 1, $node);
    }
}
