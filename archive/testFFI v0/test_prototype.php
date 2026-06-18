<?php

declare(strict_types=1);

//================================================================
// test_prototype.php
//
// Exercises the PHP/COBOL prototype:
//   - Layout parsing and dump
//   - Elementary field read/write (Display, Binary, Packed, Float)
//   - REDEFINES (zero-copy shared buffer)
//   - attach() (loading raw bytes from a simulated file read)
//   - OCCURS table access
//   - Condition names (88)
//   - search_all() binary search
//   - move_corresponding()
//   - rawBytes() / dump()
//================================================================

require_once __DIR__ . '/autoload.php';

use CobPhp\WssContext;
use function CobPhp\search_all;
use function CobPhp\move_corresponding;

//----------------------------------------------------------------
// Helper: print a test result
//----------------------------------------------------------------
function check(string $label, mixed $got, mixed $expected): void
{
    $pass = ($got === $expected);
    $status = $pass ? '  OK ' : ' FAIL';
    echo "$status  $label\n";
    if (! $pass) {
        echo "       expected: " . var_export($expected, true) . "\n";
        echo "       got:      " . var_export($got, true) . "\n";
    }
}

function section(string $title): void
{
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "  $title\n";
    echo str_repeat('=', 60) . "\n";
}

//================================================================
// BOOTSTRAP
//================================================================

$wss = CobPhp\bootstrap(__DIR__ . '/wss_simple.cobphp');

section('1. LAYOUT DUMP');
echo $wss->dumpLayouts();


//================================================================
// 2. ELEMENTARY FIELD ACCESS — Display (string)
//================================================================
section('2. DISPLAY FIELDS (string)');

$id = $wss->getRecord('WsIdentification');

$id->set('programName', 'SHOWCASE');
check('set/get string<10>',
    rtrim($id->get('programName')),
    'SHOWCASE'
);

$id->set('programName', 'HI');
check('string left-aligned + space-padded',
    $id->get('programName'),
    'HI        '   // 10 chars
);


//================================================================
// 3. ELEMENTARY FIELD ACCESS — Display (numeric)
//================================================================
section('3. DISPLAY FIELDS (numeric uint / int)');

$id->set('version', 42);
check('set/get uint<2>',  $id->get('version'),  42);

$id->set('revision', 99);
check('set/get uint<2>',  $id->get('revision'), 99);


//================================================================
// 4. BINARY FIELD
//================================================================
section('4. BINARY FIELD');

$num = $wss->getRecord('WsNumeric');

$num->set('binCounter', 123456789);
check('set/get int<9> binary', $num->get('binCounter'), 123456789);

$num->set('binCounter', -42);
check('set/get negative int<9> binary', $num->get('binCounter'), -42);


//================================================================
// 5. PACKED DECIMAL (COMP-3)
//================================================================
section('5. PACKED DECIMAL (COMP-3)');

$num->set('packedAmount', 12345.67);
$got = $num->get('packedAmount');
check('set/get sdecimal<7,2> packed', round($got, 2), 12345.67);

$num->set('packedAmount', -99.50);
$got = $num->get('packedAmount');
check('set/get negative packed', round($got, 2), -99.50);


//================================================================
// 6. FLOAT64
//================================================================
section('6. FLOAT64 (COMP-2)');

$num->set('floatVal', 3.14159265);
$got = $num->get('floatVal');
check('set/get float64', round($got, 8), 3.14159265);


//================================================================
// 7. REDEFINES — zero-copy shared buffer
//================================================================
section('7. REDEFINES (shared buffer)');

$dateNum   = $wss->getRecord('WsDateNumeric');
$dateParts = $wss->getRecord('WsDateParts');

// write through the numeric view
$dateNum->set('date', 20260604);
echo "  WsDateNumeric->date = " . $dateNum->get('date') . "\n";

// read through the structured view — same bytes, no copy
$yyyy = $dateParts->get('yyyy');
$mm   = $dateParts->get('mm');
$dd   = $dateParts->get('dd');
echo "  WsDateParts: yyyy=$yyyy  mm=$mm  dd=$dd\n";

check('redefines: yyyy', (int)$yyyy, 2026);
check('redefines: mm',   (int)$mm,     6);
check('redefines: dd',   (int)$dd,     4);

// write through parts, read back through numeric
$dateParts->set('yyyy', 1999);
$dateParts->set('mm',   12);
$dateParts->set('dd',   31);
$dateBack = $dateNum->get('date');
echo "  After writing parts, WsDateNumeric->date = $dateBack\n";
check('redefines write-back', (int)$dateBack, 19991231);


//================================================================
// 8. ATTACH — simulate loading raw bytes from a file
//================================================================
section('8. ATTACH (simulate file read — zero copy)');

// Build a raw 14-byte record manually:
//   bytes 0-9:  program name  'FILELOADED'  (10 ASCII chars)
//   bytes 10-11: version  03  (2 ASCII digits)
//   bytes 12-13: revision 07  (2 ASCII digits)
$rawRecord = 'FILELOADED' . '03' . '07';
echo "  Raw bytes: '$rawRecord'  (" . strlen($rawRecord) . " bytes)\n";

$id->attach($rawRecord);

check('attach: programName', rtrim($id->get('programName')), 'FILELOADED');
check('attach: version',     (int)$id->get('version'),       3);
check('attach: revision',    (int)$id->get('revision'),      7);

echo "\n  Hex dump after attach():\n";
echo $id->dump();


//================================================================
// 9. OCCURS — fixed table
//================================================================
section('9. OCCURS (fixed table)');

$cal = $wss->getRecord('WsCalendar');

// populate month table
$months = [
    1  => ['JAN',31], 2  => ['FEB',28], 3  => ['MAR',31],
    4  => ['APR',30], 5  => ['MAY',31], 6  => ['JUN',30],
    7  => ['JUL',31], 8  => ['AUG',31], 9  => ['SEP',30],
    10 => ['OCT',31], 11 => ['NOV',30], 12 => ['DEC',31],
];

foreach ($months as $idx => [$name, $days]) {
    $cal->cell('month', $idx)->set('monthName', $name);
    $cal->cell('month', $idx)->set('monthDays', $days);
    $cal->cell('month', $idx)->set('monthTotal', $idx * 100.00);
}

check('occurs cell 1 monthName', rtrim($cal->cell('month', 1)->get('monthName')), 'JAN');
check('occurs cell 6 monthName', rtrim($cal->cell('month', 6)->get('monthName')), 'JUN');
check('occurs cell 12 monthName',rtrim($cal->cell('month',12)->get('monthName')), 'DEC');
check('occurs cell 3 monthDays', (int)$cal->cell('month', 3)->get('monthDays'), 31);
check('occurs cell 4 monthDays', (int)$cal->cell('month', 4)->get('monthDays'), 30);
check('occurs cell 6 monthTotal',
    round((float)$cal->cell('month', 6)->get('monthTotal'), 2),
    600.00
);


//================================================================
// 10. CONDITION NAMES (88)
//================================================================
section('10. CONDITION NAMES (88)');

$status = $wss->getRecord('WsStatus');

$status->set('statusCode', 'OK');
check('isOk   when statusCode=OK', $status->isCond('isOk'),    true);
check('isError when statusCode=OK', $status->isCond('isError'), false);

$status->set('statusCode', 'ER');
check('isOk   when statusCode=ER', $status->isCond('isOk'),    false);
check('isError when statusCode=ER', $status->isCond('isError'), true);

// SET condition TO TRUE (reverse map)
$status->setCond('isOk', true);
check('setCond isOk → statusCode=OK',
    rtrim($status->get('statusCode')), 'OK');

// range condition
$status->set('returnCode', 0);
check('isSuccess when returnCode=0',  $status->isCond('isSuccess'), true);
check('isWarning when returnCode=0',  $status->isCond('isWarning'), false);

$status->set('returnCode', 3);
check('isWarning when returnCode=3',  $status->isCond('isWarning'), true);
check('isSuccess when returnCode=3',  $status->isCond('isSuccess'), false);

$status->set('returnCode', 50);
check('isBadError when returnCode=50', $status->isCond('isBadError'), true);


//================================================================
// 11. SEARCH ALL (binary search on sorted table)
//================================================================
section('11. SEARCH ALL (binary search)');

$codes = $wss->getRecord('WsCodeTable');

// populate sorted table (must be in ascending key order)
$data = [
    1 => ['ALPHA', 'First entry'],
    2 => ['BRAVO', 'Second entry'],
    3 => ['DELTA', 'Third entry'],
    4 => ['ECHO ', 'Fourth entry'],
    5 => ['FOXT ', 'Fifth entry'],
];
foreach ($data as $idx => [$code, $desc]) {
    $codes->cell('codeEntry', $idx)->set('code', $code);
    $codes->cell('codeEntry', $idx)->set('description', $desc);
}

$idx = search_all($codes, 'codeEntry', 'code', 'DELTA');
check('search_all found DELTA at index 3', $idx, 3);

$desc = rtrim($codes->cell('codeEntry', $idx)->get('description'));
check('search_all result description', $desc, 'Third entry');

$idx = search_all($codes, 'codeEntry', 'code', 'ZZZZ ');
check('search_all not found returns null', $idx, null);


//================================================================
// 12. MOVE CORRESPONDING
//================================================================
section('12. MOVE CORRESPONDING');

$id2 = $wss->getRecord('WsIdentification');
// WsIdentification has: programName, version, revision
// Write to id, then move_corresponding to a second record if present
// (for this test we copy within the same record via a fresh instance)

$id->set('programName', 'SOURCE');
$id->set('version',     7);
$id->set('revision',    3);

// create a second layout-compatible record manually for the test
$id3 = new \CobPhp\CobPhpRecord($id->layout);
move_corresponding($id, $id3);

check('move_corresponding programName',
    rtrim($id3->get('programName')), 'SOURCE');
check('move_corresponding version',  (int)$id3->get('version'),  7);
check('move_corresponding revision', (int)$id3->get('revision'), 3);


//================================================================
// 13. rawBytes() — export buffer for file write
//================================================================
section('13. rawBytes() — export for file write');

$id->set('programName', 'WRITETEST');
$id->set('version',     1);
$id->set('revision',    0);

$raw = $id->rawBytes();
echo "  rawBytes length: " . strlen($raw) . " (expected {$id->layout->totalLength})\n";
echo "  first 10 chars:  '" . substr($raw, 0, 10) . "'\n";
check('rawBytes length', strlen($raw), $id->layout->totalLength);
check('rawBytes content', substr($raw, 0, 9), 'WRITETEST');


//================================================================
// SUMMARY
//================================================================
echo "\n" . str_repeat('=', 60) . "\n";
echo "  Prototype test complete.\n";
echo str_repeat('=', 60) . "\n\n";
