<?php

declare(strict_types=1);

namespace Accord\Core;

enum ApplyResult: string
{
    case Applied = 'applied';
    case Duplicate = 'duplicate';
}
