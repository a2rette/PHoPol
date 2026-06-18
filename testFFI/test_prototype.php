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

use PHoPol\WssContext;
use function PHoPol\search_all;
use function PHoPol\move_corresponding;
use function PHoPol\add_corresponding;
use function PHoPol\edit_format;
use function PHoPol\initialize;

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

$wss = PHoPol\bootstrap(__DIR__ . '/wss_simple.phopol');

section('1. LAYOUT DUMP');
echo $wss->dumpLayouts();


//================================================================
// 2. ELEMENTARY FIELD ACCESS â€” Display (string)
//================================================================
section('2. DISPLAY FIELDS (string)');

$id = $wss->getLevel('WsIdentification');

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
// 3. ELEMENTARY FIELD ACCESS â€” Display (numeric)
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

$num = $wss->getLevel('WsNumeric');

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
// 7. REDEFINES â€” zero-copy shared buffer
//================================================================
section('7. REDEFINES (shared buffer)');

$dateNum   = $wss->getLevel('WsDateNumeric');
$dateParts = $wss->getLevel('WsDateParts');

// write through the numeric view
$dateNum->set('date', 20260604);
echo "  WsDateNumeric->date = " . $dateNum->get('date') . "\n";

// read through the structured view â€” same bytes, no copy
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
// 8. ATTACH â€” simulate loading raw bytes from a file
//================================================================
section('8. ATTACH (simulate file read â€” zero copy)');

// Build a raw 14-byte level manually:
//   bytes 0-9:  program name  'FILELOADED'  (10 ASCII chars)
//   bytes 10-11: version  03  (2 ASCII digits)
//   bytes 12-13: revision 07  (2 ASCII digits)
$rawLevel = 'FILELOADED' . '03' . '07';
echo "  Raw bytes: '$rawLevel'  (" . strlen($rawLevel) . " bytes)\n";

$id->attach($rawLevel);

check('attach: programName', rtrim($id->get('programName')), 'FILELOADED');
check('attach: version',     (int)$id->get('version'),       3);
check('attach: revision',    (int)$id->get('revision'),      7);

echo "\n  Hex dump after attach():\n";
echo $id->dump();


//================================================================
// 9. OCCURS â€” fixed table
//================================================================
section('9. OCCURS (fixed table)');

$cal = $wss->getLevel('WsCalendar');

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

$status = $wss->getLevel('WsStatus');

$status->set('statusCode', 'OK');
check('isOk   when statusCode=OK', $status->isCond('isOk'),    true);
check('isError when statusCode=OK', $status->isCond('isError'), false);

$status->set('statusCode', 'ER');
check('isOk   when statusCode=ER', $status->isCond('isOk'),    false);
check('isError when statusCode=ER', $status->isCond('isError'), true);

// SET condition TO TRUE (reverse map)
$status->setCond('isOk', true);
check('setCond isOk â†’ statusCode=OK',
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

$codes = $wss->getLevel('WsCodeTable');

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

$id2 = $wss->getLevel('WsIdentification');
// WsIdentification has: programName, version, revision
// Write to id, then move_corresponding to a second level if present
// (for this test we copy within the same level via a fresh instance)

$id->set('programName', 'SOURCE');
$id->set('version',     7);
$id->set('revision',    3);

// create a second layout-compatible level manually for the test
$id3 = new \PHoPol\PHoPolLevel01($id->layout);
move_corresponding($id, $id3);

check('move_corresponding programName',
    rtrim($id3->get('programName')), 'SOURCE');
check('move_corresponding version',  (int)$id3->get('version'),  7);
check('move_corresponding revision', (int)$id3->get('revision'), 3);


//================================================================
// 13. rawBytes() â€” export buffer for file write
//================================================================
section('13. rawBytes() â€” export for file write');

$id->set('programName', 'WRITETEST');
$id->set('version',     1);
$id->set('revision',    0);

$raw = $id->rawBytes();
echo "  rawBytes length: " . strlen($raw) . " (expected {$id->layout->totalLength})\n";
echo "  first 10 chars:  '" . substr($raw, 0, 10) . "'\n";
check('rawBytes length', strlen($raw), $id->layout->totalLength);
check('rawBytes content', substr($raw, 0, 9), 'WRITETEST');


//================================================================
// 14. WsEmployee â€” multi-level nested field access
//================================================================
section('14. WsEmployee â€” nested levels, mixed types, conditions');

$emp = $wss->getLevel('WsEmployee');

$emp->set('employeeId',       'E00042');
$emp->set('employeeName',     'John Smith');
$emp->set('employeeGender',   'M');
$emp->set('employeeStatus',   'A');
$emp->set('baseSalary',       75000.00);
$emp->set('bonus',            5000.00);
$emp->set('totalComp',        80000.00);
$emp->set('payGrade',         8);
$emp->set('hireDate',         20150315);
$emp->set('reviewDate',       20260101);
$emp->set('yearsService',     11);
$emp->set('performanceScore', 4.75);

check('employeeId',
    rtrim($emp->get('employeeId')), 'E00042');
check('employeeName',
    rtrim($emp->get('employeeName')), 'John Smith');
check('employeeGender',
    rtrim($emp->get('employeeGender')), 'M');
check('baseSalary',
    round((float)$emp->get('baseSalary'), 2), 75000.00);
check('bonus',
    round((float)$emp->get('bonus'), 2), 5000.00);
check('totalComp',
    round((float)$emp->get('totalComp'), 2), 80000.00);
check('payGrade',
    (int)$emp->get('payGrade'), 8);
check('hireDate',
    (int)$emp->get('hireDate'), 20150315);
check('yearsService',
    (int)$emp->get('yearsService'), 11);
check('performanceScore',
    round((float)$emp->get('performanceScore'), 2), 4.75);

// 88-level conditions inside a nested level(05)
check('isActive (A)',    $emp->isCond('isActive'),     true);
check('isRetired (A)',   $emp->isCond('isRetired'),    false);
check('isTerminated (A)',$emp->isCond('isTerminated'), false);

$emp->set('employeeStatus', 'R');
check('isActive (R)',    $emp->isCond('isActive'),  false);
check('isRetired (R)',   $emp->isCond('isRetired'), true);
$emp->set('employeeStatus', 'A'); // restore


//================================================================
// 15. move_corresponding â€” level(01) to level(01), partial match
//================================================================
section('15. move_corresponding â€” WsEmployee â†’ WsEmployeeCopy (subset)');

$empCopy = $wss->getLevel('WsEmployeeCopy');
move_corresponding($emp, $empCopy);

// Matching fields: employeeId, employeeName, baseSalary, bonus, totalComp, payGrade
check('copy employeeId',
    rtrim($empCopy->get('employeeId')), 'E00042');
check('copy employeeName',
    rtrim($empCopy->get('employeeName')), 'John Smith');
check('copy baseSalary',
    round((float)$empCopy->get('baseSalary'), 2), 75000.00);
check('copy bonus',
    round((float)$empCopy->get('bonus'), 2), 5000.00);
check('copy totalComp',
    round((float)$empCopy->get('totalComp'), 2), 80000.00);
check('copy payGrade',
    (int)$empCopy->get('payGrade'), 8);

// Fields absent from WsEmployeeCopy must NOT appear in its layout
check('WsEmployeeCopy has no hireDate',
    $empCopy->layout->getField('hireDate'), null);
check('WsEmployeeCopy has no performanceScore',
    $empCopy->layout->getField('performanceScore'), null);


//================================================================
// 16. WsOrder â€” OCCURS detail lines via cell()
//================================================================
section('16. WsOrder â€” OCCURS lines, cell() returns PHoPolLevel');

$order = $wss->getLevel('WsOrder');

$order->set('orderId',      'ORD-2026-001');
$order->set('orderDate',    20260613);
$order->set('customerName', 'Acme Corporation');
$order->set('lineCount',    3);

$lines = [
    1 => ['WIDGET-A  ', 10, 29.99,  299.90],
    2 => ['GADGET-B  ',  5, 149.95, 749.75],
    3 => ['DOOHICK-C ',  2, 399.00, 798.00],
];

foreach ($lines as $i => [$code, $qty, $price, $total]) {
    $line = $order->cell('orderLine', $i);
    $line->set('lineCode',  $code);
    $line->set('lineQty',   $qty);
    $line->set('linePrice', $price);
    $line->set('lineTotal', $total);
}

$order->set('orderGrandTotal', 299.90 + 749.75 + 798.00);

check('orderId',
    rtrim($order->get('orderId')), 'ORD-2026-001');
check('line 1 code',
    rtrim($order->cell('orderLine', 1)->get('lineCode')), 'WIDGET-A');
check('line 1 qty',
    (int)$order->cell('orderLine', 1)->get('lineQty'), 10);
check('line 2 price',
    round((float)$order->cell('orderLine', 2)->get('linePrice'), 2), 149.95);
check('line 3 total',
    round((float)$order->cell('orderLine', 3)->get('lineTotal'), 2), 798.00);
check('orderGrandTotal',
    round((float)$order->get('orderGrandTotal'), 2), 1847.65);

// cell() returns a PHoPolLevel, not PHoPolLevel01
check('cell() is PHoPolLevel',
    ($order->cell('orderLine', 1) instanceof \PHoPol\PHoPolLevel), true);
check('cell() is NOT PHoPolLevel01',
    ($order->cell('orderLine', 1) instanceof \PHoPol\PHoPolLevel01), false);


//================================================================
// 17. move_corresponding â€” between cells (PHoPolLevel objects)
//================================================================
section('17. move_corresponding â€” between cell() results');

$summary = $wss->getLevel('WsOrderSummary');

// Copy order line 2 into summary slot 1
move_corresponding($order->cell('orderLine', 2), $summary->cell('orderLine', 1));

check('move cell lineCode',
    rtrim($summary->cell('orderLine', 1)->get('lineCode')), 'GADGET-B');
check('move cell lineQty',
    (int)$summary->cell('orderLine', 1)->get('lineQty'), 5);
check('move cell linePrice',
    round((float)$summary->cell('orderLine', 1)->get('linePrice'), 2), 149.95);
check('move cell lineTotal',
    round((float)$summary->cell('orderLine', 1)->get('lineTotal'), 2), 749.75);

// Summary slot 2 must be untouched (not zeroed â€” just verify it differs)
// Copy line 3 into slot 2 and verify independence
move_corresponding($order->cell('orderLine', 3), $summary->cell('orderLine', 2));
check('move cell slot 2 qty',
    (int)$summary->cell('orderLine', 2)->get('lineQty'), 2);
check('move cell slot 1 unchanged after slot 2 write',
    (int)$summary->cell('orderLine', 1)->get('lineQty'), 5);


//================================================================
// 18. add_corresponding â€” accumulate lines into a summary cell
//================================================================
section('18. add_corresponding â€” accumulate 3 lines into one cell');

// Initialise accumulator slot
$accum = $summary->cell('orderLine', 3);
$accum->set('lineQty',   0);
$accum->set('linePrice', 0.00);
$accum->set('lineTotal', 0.00);

// Accumulate all 3 order lines
for ($i = 1; $i <= 3; $i++) {
    add_corresponding($order->cell('orderLine', $i), $accum);
}

// qty:   10 + 5 + 2        = 17
// total: 299.90+749.75+798  = 1847.65
check('add_corr qty sum',
    (int)$accum->get('lineQty'), 17);
check('add_corr total sum',
    round((float)$accum->get('lineTotal'), 2), 1847.65);

// Verify slots 1 and 2 were not disturbed by writes to slot 3
check('slot 1 still intact aftersentinem accum',
    (int)$summary->cell('orderLine', 1)->get('lineQty'), 5);
check('slot 2 still intact after accum',
    (int)$summary->cell('orderLine', 2)->get('lineQty'), 2);


//================================================================
// 19. Edited picture formats â€” edit_format() and auto-MOVE
//================================================================
section('19. Edited picture â€” edit_format() and auto-MOVE on set()');

// --- edit_format() unit tests ---

// ZZZ,ZZZ,ZZ9.99 â€” 9 int slots, 2 decimal slots, 14 chars total
check('edit ZZZ,ZZZ,ZZ9.99 large value',
    edit_format('ZZZ,ZZZ,ZZ9.99', 1234567.89), '  1,234,567.89');
check('edit ZZZ,ZZZ,ZZ9.99 medium value (75000)',
    edit_format('ZZZ,ZZZ,ZZ9.99', 75000.00),   '     75,000.00');
check('edit ZZZ,ZZZ,ZZ9.99 small value (5.25)',
    edit_format('ZZZ,ZZZ,ZZ9.99', 5.25),        '          5.25');
check('edit ZZZ,ZZZ,ZZ9.99 zero',
    edit_format('ZZZ,ZZZ,ZZ9.99', 0),            '          0.00');

// ZZZ,ZZZ.99 â€” 6 int slots, 10 chars total
check('edit ZZZ,ZZZ.99 value (5000)',
    edit_format('ZZZ,ZZZ.99', 5000.00), '  5,000.00');
check('edit ZZZ,ZZZ.99 zero',
    edit_format('ZZZ,ZZZ.99', 0),       '      0.00');

// 9999/99/99 â€” date insertion (YYYYMMDD â†’ YYYY/MM/DD)
check('edit 9999/99/99 date',
    edit_format('9999/99/99', 20150315), '2015/03/15');
check('edit 9999/99/99 date 2',
    edit_format('9999/99/99', 20261231), '2026/12/31');

// Z9 â€” small integer with zero suppress
check('edit Z9 single digit',
    edit_format('Z9', 8),  ' 8');
check('edit Z9 two digits',
    edit_format('Z9', 12), '12');
check('edit Z9 zero',
    edit_format('Z9', 0),  ' 0');

// V â€” virgule virtuelle: decimal digits flow with no printed separator
check('edit ZZZ,ZZZ,ZZ9V99 (V decimal, no dot printed)',
    edit_format('ZZZ,ZZZ,ZZ9V99', 75000.00), '     75,00000');
check('edit ZZ9V99 zero',
    edit_format('ZZ9V99', 0),                '  000');

// ZZ9.99 â€” performance score
check('edit ZZ9.99 value (4.75)',
    edit_format('ZZ9.99', 4.75),  '  4.75');
check('edit ZZ9.99 value (10.00)',
    edit_format('ZZ9.99', 10.00), ' 10.00');
check('edit ZZ9.99 value (100.00) overflow clips',
    edit_format('ZZ9.99', 100.00), '100.00');

// decimal point is comma â€” '.' = thousands inserter, ',' = decimal separator
check('edit DPIC ZZZ.ZZZ.ZZ9,99 large (1234567.89)',
    edit_format('ZZZ.ZZZ.ZZ9,99', 1234567.89, true), '  1.234.567,89');
check('edit DPIC ZZZ.ZZZ.ZZ9,99 medium (75000)',
    edit_format('ZZZ.ZZZ.ZZ9,99', 75000.00,   true), '     75.000,00');
check('edit DPIC ZZZ.ZZZ.ZZ9,99 small (5.25)',
    edit_format('ZZZ.ZZZ.ZZ9,99', 5.25,        true), '          5,25');
check('edit DPIC ZZZ.ZZZ.ZZ9,99 zero',
    edit_format('ZZZ.ZZZ.ZZ9,99', 0,            true), '          0,00');

// shorter mask
check('edit DPIC ZZZ.ZZZ,99 value (5000)',
    edit_format('ZZZ.ZZZ,99', 5000.00, true), '  5.000,00');
check('edit DPIC ZZZ.ZZZ,99 zero',
    edit_format('ZZZ.ZZZ,99', 0,       true), '      0,00');

// simple mask
check('edit DPIC ZZ9,99 (4.75)',
    edit_format('ZZ9,99', 4.75, true), '  4,75');

// V (virgule virtuelle) is unaffected by DPIC â€” no printed separator
check('edit DPIC ZZ9V99 zero (V still implied, no char)',
    edit_format('ZZ9V99', 0, true), '  000');


// --- Auto-MOVE: set() on an edited field formats automatically ---

$rpt = $wss->getLevel('WsReportLine');

$rpt->set('rlId',       'E00042');
$rpt->set('rlName',     'John Smith');
$rpt->set('rlSalary',   75000.00);       // numeric â†’ auto-edited on set()
$rpt->set('rlBonus',    5000.00);
$rpt->set('rlHireDate', 20150315);
$rpt->set('rlGrade',    8);
$rpt->set('rlScore',    4.75);
$rpt->set('rlMarker',   ' ');

check('auto-MOVE rlSalary formats on set()',
    $rpt->get('rlSalary'), '     75,000.00');
check('auto-MOVE rlBonus formats on set()',
    $rpt->get('rlBonus'),  '  5,000.00');
check('auto-MOVE rlHireDate formats on set()',
    $rpt->get('rlHireDate'), '2015/03/15');
check('auto-MOVE rlGrade formats on set()',
    $rpt->get('rlGrade'), ' 8');
check('auto-MOVE rlScore formats on set()',
    $rpt->get('rlScore'), '  4.75');

// rawBytes() delivers a ready-to-print line (filler bytes are spaces)
$line = $rpt->rawBytes();
check('rawBytes() line length', strlen($line), 86);
check('filler bytes are spaces',
    $line[6] === ' ' && $line[37] === ' ' && $line[38+14] === ' ', true);

// String passthrough: setting an already-formatted string to an edited
// field stores it as-is (no double-formatting)
$rpt->set('rlSalary', '     75,000.00');
check('string passthrough unchanged',
    $rpt->get('rlSalary'), '     75,000.00');


//================================================================
// 20. INITIALIZE â€” reset fields to category defaults
//================================================================
section('20. INITIALIZE â€” alphanumeric â†’ SPACES, numeric â†’ ZERO');

// --- INITIALIZE on a level(01) level ---

// WsEmployee already has values from section 14; initialize it and check
initialize($emp);

check('INITIALIZE string â†’ spaces',
    $emp->get('employeeId'), str_repeat(' ', 6));
check('INITIALIZE alpha â†’ spaces',
    $emp->get('employeeGender'), ' ');
check('INITIALIZE packed â†’ zero',
    $emp->get('baseSalary'), 0.0);
check('INITIALIZE binary â†’ zero',
    (int)$emp->get('payGrade'), 0);
check('INITIALIZE float â†’ zero',
    $emp->get('performanceScore'), 0.0);
check('INITIALIZE uint display â†’ zero',
    (int)$emp->get('hireDate'), 0);

// --- INITIALIZE on WsReportLine â€” numeric-edited fields get MOVE ZERO â†’ mask ---

initialize($rpt);

check('INITIALIZE numeric-edited rlSalary â†’ zero through mask',
    $rpt->get('rlSalary'), edit_format('ZZZ,ZZZ,ZZ9.99', 0));
check('INITIALIZE numeric-edited rlHireDate â†’ zero through mask',
    $rpt->get('rlHireDate'), edit_format('9999/99/99', 0));
check('INITIALIZE rlId (alphanumeric) â†’ spaces',
    $rpt->get('rlId'), str_repeat(' ', 6));
check('INITIALIZE rlMarker â†’ space',
    $rpt->get('rlMarker'), ' ');

// --- INITIALIZE multiple levels in one call ---

$order->set('orderId', 'ORD-TEST');
$order->set('orderGrandTotal', 999.99);
$summary->set('summaryTotal', 123.45);

initialize($order, $summary);

check('INITIALIZE multi: orderId â†’ spaces',
    trim($order->get('orderId')), '');
check('INITIALIZE multi: orderGrandTotal â†’ zero',
    $order->get('orderGrandTotal'), 0.0);
check('INITIALIZE multi: summaryTotal â†’ zero',
    $summary->get('summaryTotal'), 0.0);

// --- INITIALIZE on a cell (PHoPolLevel, not Level01) ---
// At this point $order has been fully initialized (all cells zeroed).
// Set known values in both cells, then initialize only cell 1.
$order->cell('orderLine', 1)->set('lineQty',  42);
$order->cell('orderLine', 1)->set('lineTotal', 99.99);
$order->cell('orderLine', 2)->set('lineQty',  7);   // sentinel: must survive

initialize($order->cell('orderLine', 1));

check('INITIALIZE cell: lineQty â†’ zero',
    (int)$order->cell('orderLine', 1)->get('lineQty'), 0);
check('INITIALIZE cell: lineTotal â†’ zero',
    $order->cell('orderLine', 1)->get('lineTotal'), 0.0);
check('INITIALIZE cell: lineCode â†’ spaces',
    trim($order->cell('orderLine', 1)->get('lineCode')), '');
// adjacent cell must be untouched
check('INITIALIZE cell: cell 2 lineQty untouched',
    (int)$order->cell('orderLine', 2)->get('lineQty'), 7);


//================================================================
// SUMMARY
//================================================================
echo "\n" . str_repeat('=', 60) . "\n";
echo "  Prototype test complete.\n";
echo str_repeat('=', 60) . "\n\n";
