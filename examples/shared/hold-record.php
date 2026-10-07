<?php

declare(strict_types=1);

/*
 * The helper process of the control API's /hold-record (test-only; see RecordHold). Started by
 * RecordHold::hold(), never by hand:
 *
 *   ACCORD_HOLD_DATABASE_URL=postgres://… php hold-record.php <state dir> <record>
 */

require_once __DIR__ . '/RecordHold.php';

/** @var list<string> $argv */
exit(Accord\Examples\RecordHold::serve($argv));
