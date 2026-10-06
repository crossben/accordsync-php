<?php

declare(strict_types=1);

namespace Accord\Core;

/** A remote clock too far ahead of this one: the op is refused instead of winning every merge. */
final class ClockSkewException extends AccordException {}
