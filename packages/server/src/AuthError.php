<?php

declare(strict_types=1);

namespace Accord\Server;

/** 401 Unauthorized: missing or invalid token. */
final class AuthError extends \RuntimeException {}
