<?php
// php.exe -d extension=php_phopol.dll -d error_reporting=E_ALL -d display_errors=1 test_loadSection.php
//
// End-to-end test: parse wss_simple.phopol → register with C extension →
// read/write fields through PHoPolLevel01 objects.

require_once __DIR__ . '/../ext/loadSection.php';

use function PHoPol\loadSection;

$levels = loadSection(__DIR__ . '/../testFFI/wss_simple.phopol');

$pass = 0;
$fail = 0;

function check(string $label, mixed $got, mixed $expected, float $eps = 0.0001): void {
    global $pass, $fail;
    $ok = is_float($expected)
        ? abs((float)$got - $expected) < $eps
        : $got === $expected;
    if ($ok) {
        echo "  OK   $label\n";
        $pass++;
    } else {
        echo "  FAIL $label: got=" . var_export($got, true)
           . " expected=" . var_export($expected, true) . "\n";
        $fail++;
    }
}

// -------------------------------------------------------------------
// 1. WsIdentification — string + uint fields
// -------------------------------------------------------------------
echo "============================================================\n";
echo "  1. WsIdentification\n";
echo "============================================================\n";

$WsIdentification = $levels['WsIdentification'];
$WsIdentification->programName = 'BOOTSTRAP';
$WsIdentification->version     = 2;
$WsIdentification->revision    = 99;

check('programName set',  $WsIdentification->programName, 'BOOTSTRAP ');
check('version set',      $WsIdentification->version,     2);
check('revision set',     $WsIdentification->revision,    99);

// truncation at field boundary (field is 10 bytes)
$WsIdentification->programName = 'TOOLONGNAME!';
check('programName truncated', $WsIdentification->programName, 'TOOLONGNAM');

// -------------------------------------------------------------------
// 2. WsDateNumeric / WsDateParts — REDEFINES (shared buffer)
// -------------------------------------------------------------------
echo "\n============================================================\n";
echo "  2. REDEFINES\n";
echo "============================================================\n";

$WsDateNumeric  = $levels['WsDateNumeric'];
$WsDateParts = $levels['WsDateParts'];

$WsDateNumeric->date = 20260615;
check('WsDateNumeric->date',   $WsDateNumeric->date,   20260615);
check('WsDateParts->yyyy',     $WsDateParts->yyyy,  2026);
check('WsDateParts->mm',       $WsDateParts->mm,    6);
check('WsDateParts->dd',       $WsDateParts->dd,    15);

// Write through the redefines side, read through the base
$WsDateParts->yyyy = 1999;
$WsDateParts->mm   = 12;
$WsDateParts->dd   = 31;
check('after writing parts, WsDateNumeric->date', $WsDateNumeric->date, 19991231);

// -------------------------------------------------------------------
// 3. WsNumeric — binary int, packed decimal, float64
// -------------------------------------------------------------------
echo "\n============================================================\n";
echo "  3. WsNumeric (binary, packed, float)\n";
echo "============================================================\n";

$WsNumeric = $levels['WsNumeric'];
$WsNumeric->binCounter   = -987654321;
$WsNumeric->packedAmount = 12345.67;
$WsNumeric->floatVal     = 3.14159265;

check('binCounter',   $WsNumeric->binCounter,   -987654321);
check('packedAmount', $WsNumeric->packedAmount, 12345.67);
check('floatVal',     $WsNumeric->floatVal,     3.14159265, 0.000001);

$WsNumeric->packedAmount = -0.01;
check('packedAmount negative', $WsNumeric->packedAmount, -0.01);

// -------------------------------------------------------------------
// 4. WsEmployee — multi-level flattened, mixed types
// -------------------------------------------------------------------
echo "\n============================================================\n";
echo "  4. WsEmployee\n";
echo "============================================================\n";

$WsEmployee = $levels['WsEmployee'];
$WsEmployee->employeeId     = 'EMP001';
$WsEmployee->employeeName   = 'Jean Dupont';
$WsEmployee->employeeGender = 'M';
$WsEmployee->baseSalary     = 75000.00;
$WsEmployee->bonus          = 5000.50;
$WsEmployee->totalComp      = 80000.50;
$WsEmployee->payGrade       = 3;
$WsEmployee->hireDate       = 20150101;
$WsEmployee->yearsService   = 11;
$WsEmployee->performanceScore = 4.75;

check('employeeId',       $WsEmployee->employeeId,       'EMP001');
check('employeeName',     $WsEmployee->employeeName,     'Jean Dupont                   ');
check('employeeGender',   $WsEmployee->employeeGender,   'M');
check('baseSalary',       $WsEmployee->baseSalary,       75000.00);
check('bonus',            $WsEmployee->bonus,            5000.50);
check('totalComp',        $WsEmployee->totalComp,        80000.50);
check('payGrade',         $WsEmployee->payGrade,         3);
check('hireDate',         $WsEmployee->hireDate,         20150101);
check('yearsService',     $WsEmployee->yearsService,     11);
check('performanceScore', $WsEmployee->performanceScore, 4.75, 0.0001);

// -------------------------------------------------------------------
// 5. rawBytes round-trip
// -------------------------------------------------------------------
echo "\n============================================================\n";
echo "  5. rawBytes round-trip\n";
echo "============================================================\n";

$WsIdentification->programName = 'ROUNDTRIP';
$WsIdentification->version     = 5;
$WsIdentification->revision    = 42;
$raw = $WsIdentification->rawBytes();

$id2 = new PHoPolLevel01('WsIdentification');
$id2->attach($raw);
check('round-trip programName', $id2->programName, 'ROUNDTRIP ');
check('round-trip version',     $id2->version,     5);
check('round-trip revision',    $id2->revision,    42);

// -------------------------------------------------------------------
// 6. 88-level condition names
// -------------------------------------------------------------------
echo "\n============================================================\n";
echo "  6. Condition names (88-levels)\n";
echo "============================================================\n";

$status = $levels['WsStatus'];

// VALUES conditions on an alpha field (string<2>)
$status->statusCode = 'OK';
check('isOk  when OK',    $status->isOk,    true);
check('isError when OK',  $status->isError, false);
check('isFatal when OK',  $status->isFatal, false);

$status->statusCode = 'ER';
check('isOk  when ER',    $status->isOk,    false);
check('isError when ER',  $status->isError, true);

$status->statusCode = 'FT';
check('isFatal when FT',  $status->isFatal, true);

// VALUES condition on a binary int field (== 0)
$status->returnCode = 0;
check('isSuccess when 0',  $status->isSuccess, true);
check('isWarning when 0',  $status->isWarning, false);
check('isBadError when 0', $status->isBadError, false);

// RANGE condition (in 1..4)
$status->returnCode = 3;
check('isSuccess when 3',  $status->isSuccess, false);
check('isWarning when 3',  $status->isWarning, true);
check('isBadError when 3', $status->isBadError, false);

// RANGE condition (in 8..99)
$status->returnCode = 50;
check('isSuccess when 50',  $status->isSuccess,  false);
check('isWarning when 50',  $status->isWarning,  false);
check('isBadError when 50', $status->isBadError, true);

// WsEmployee conditions
$WsEmployee = $levels['WsEmployee'];
$WsEmployee->employeeStatus = 'A';
check('isActive when A',     $WsEmployee->isActive,     true);
check('isRetired when A',    $WsEmployee->isRetired,    false);
check('isTerminated when A', $WsEmployee->isTerminated, false);

$WsEmployee->employeeStatus = 'T';
check('isTerminated when T', $WsEmployee->isTerminated, true);
check('isActive when T',     $WsEmployee->isActive,     false);

// SET TO TRUE: assign true to a condition name → writes the field value
$WsEmployee->isActive = true;
check('isActive=true sets employeeStatus to A', $WsEmployee->employeeStatus, 'A');

$WsEmployee->isTerminated = true;
check('isTerminated=true sets employeeStatus to T', $WsEmployee->employeeStatus, 'T');

// -------------------------------------------------------------------
// 7. OCCURS — cell() access
// -------------------------------------------------------------------
echo "\n============================================================\n";
echo "  7. OCCURS — cell() access\n";
echo "============================================================\n";

$WsCalendar = $levels['WsCalendar'];

// Write cell 3 and read it back via a fresh sub-view
$WsCalendar->month[3]->monthName  = 'March';
$WsCalendar->month[3]->monthDays  = 31;
$WsCalendar->month[3]->monthTotal = 15250.75;

check('month[3] monthName',  rtrim($WsCalendar->month[3]->monthName),  'March');
check('month[3] monthDays',  $WsCalendar->month[3]->monthDays,          31);
check('month[3] monthTotal', $WsCalendar->month[3]->monthTotal,         15250.75);

// Cell 1 must not bleed into cell 3
$WsCalendar->month[1]->monthName = 'January';
$WsCalendar->month[1]->monthDays = 31;
check('month[3] unchanged after month[1] write', rtrim($WsCalendar->month[3]->monthName), 'March');
check('month[1] monthName', rtrim($WsCalendar->month[1]->monthName), 'January');

// Variable assignment: $line = $WsCalendar->month[1] works as a live sub-view
$jan = $WsCalendar->month[1];
$jan->monthName = 'January!';
check('month[1] monthName via $jan', rtrim($WsCalendar->month[1]->monthName), 'January!');

// rawBytes() on a cell sub-view returns exactly entry_size (17) bytes
check('cell rawBytes length == entry_size', strlen($WsCalendar->month[1]->rawBytes()), 17);

// rawBytes() returns THIS cell's bytes, not another cell's
// month[1] starts with 'January! ' (9 bytes), month[3] starts with 'March    '
check('month[1] rawBytes starts with monthName',
    substr($WsCalendar->month[1], 0, 9), 'January! ');
check('month[3] rawBytes starts with monthName',
    substr($WsCalendar->month[3], 0, 9), 'March    ');
check('month[1] and month[3] rawBytes differ',
    $WsCalendar->month[1] !== $WsCalendar->month[3], true);

// Last cell (boundary)
$WsCalendar->month[12]->monthName  = 'December';
$WsCalendar->month[12]->monthDays  = 31;
$WsCalendar->month[12]->monthTotal = 98765.00;
check('month[12] monthName',  rtrim($WsCalendar->month[12]->monthName),  'December');
check('month[12] monthDays',  $WsCalendar->month[12]->monthDays,          31);
check('month[12] monthTotal', $WsCalendar->month[12]->monthTotal,         98765.00);

// isset() on group name and valid/invalid index
check('isset(month)',    isset($WsCalendar->month),     true);
check('isset(month[3])', isset($WsCalendar->month[3]), true);
check('isset(month[13])', isset($WsCalendar->month[13]), false);

// WsCodeTable — string-only cells
$WsCodeTable = $levels['WsCodeTable'];
$WsCodeTable->codeEntry[1]->code        = 'AAA01';
$WsCodeTable->codeEntry[1]->description = 'First entry';
$WsCodeTable->codeEntry[5]->code        = 'ZZZ99';
$WsCodeTable->codeEntry[5]->description = 'Last entry';

check('codeEntry[1] code',        rtrim($WsCodeTable->codeEntry[1]->code),        'AAA01');
check('codeEntry[1] description', rtrim($WsCodeTable->codeEntry[1]->description), 'First entry');
check('codeEntry[5] code',        rtrim($WsCodeTable->codeEntry[5]->code),        'ZZZ99');
check('codeEntry[5] description', rtrim($WsCodeTable->codeEntry[5]->description), 'Last entry');
check('cells independent', rtrim($WsCodeTable->codeEntry[1]->code), 'AAA01');

// rawBytes() on codeEntry cells: 5 (code) + 20 (description) = 25 bytes/cell
check('codeEntry[1] rawBytes length', strlen($WsCodeTable->codeEntry[1]), 25);
check('codeEntry[5] rawBytes length', strlen($WsCodeTable->codeEntry[5]), 25);
check('codeEntry[1] rawBytes code field',
    substr($WsCodeTable->codeEntry[1], 0, 5), 'AAA01');
check('codeEntry[5] rawBytes code field',
    substr($WsCodeTable->codeEntry[5], 0, 5), 'ZZZ99');
check('codeEntry[1] rawBytes description field',
    substr($WsCodeTable->codeEntry[1], 5, 20), 'First entry         ');
check('codeEntry[5] rawBytes description field',
    substr($WsCodeTable->codeEntry[5], 5, 20), 'Last entry          ');
check('codeEntry[1] and codeEntry[5] rawBytes differ',
    $WsCodeTable->codeEntry[1] !== $WsCodeTable->codeEntry[5], true);

// WsOrderSummary — packed-decimal cells
$WsOrderSummary = $levels['WsOrderSummary'];
for ($i = 1; $i <= 5; $i++) {
    $line = $WsOrderSummary->orderLine[$i];
    $line->lineCode  = "ITEM$i";
    $line->lineQty   = $i * 10;
    $line->linePrice = 9.99 + $i;
    $line->lineTotal = (float)($i * 10) * (9.99 + $i);
}
check('orderLine[2] lineCode',  rtrim($WsOrderSummary->orderLine[2]->lineCode),  'ITEM2');
check('orderLine[2] lineQty',   $WsOrderSummary->orderLine[2]->lineQty,          20);
check('orderLine[2] linePrice', $WsOrderSummary->orderLine[2]->linePrice,        11.99);
check('orderLine[2] lineTotal', $WsOrderSummary->orderLine[2]->lineTotal,        239.80);
check('orderLine[5] lineTotal', $WsOrderSummary->orderLine[5]->lineTotal,        749.50);

// WsOrder — OCCURS group with non-zero group_base (header fields precede it)
$WsOrder = $levels['WsOrder'];
$WsOrder->orderId      = 'ORD-2026-001';
$WsOrder->orderDate    = 20260601;
$WsOrder->customerName = 'Dupont SA';
$WsOrder->lineCount    = 2;              // DEPENDING ON field must be set before accessing cells
$WsOrder->orderLine[1]->lineCode  = 'WIDGET-A';
$WsOrder->orderLine[1]->lineQty   = 100;
$WsOrder->orderLine[1]->linePrice = 4.50;
$WsOrder->orderLine[2]->lineCode  = 'GADGET-B';
$WsOrder->orderLine[2]->lineQty   = 50;
$WsOrder->orderLine[2]->linePrice = 12.00;

check('WsOrder header orderId',     rtrim($WsOrder->orderId),                        'ORD-2026-001');
check('WsOrder orderLine[1] code',  rtrim($WsOrder->orderLine[1]->lineCode),         'WIDGET-A');
check('WsOrder orderLine[1] qty',   $WsOrder->orderLine[1]->lineQty,                 100);
check('WsOrder orderLine[1] price', $WsOrder->orderLine[1]->linePrice,               4.50);
check('WsOrder orderLine[2] code',  rtrim($WsOrder->orderLine[2]->lineCode),         'GADGET-B');
check('WsOrder orderLine[2] qty',   $WsOrder->orderLine[2]->lineQty,                 50);
check('WsOrder header customerName intact', rtrim($WsOrder->customerName),            'Dupont SA');

// Out-of-bounds index throws
$threw = false;
try { $x = $WsCalendar->month[13]->monthName; } catch (\Error $e) { $threw = true; }
check('month[13] throws', $threw, true);

$threw = false;
try { $x = $WsCalendar->month[0]->monthName; } catch (\Error $e) { $threw = true; }
check('month[0] throws', $threw, true);

// Unknown group name throws
$threw = false;
try { $x = $WsCalendar->noSuchGroup[1]->foo; } catch (\Error $e) { $threw = true; }
check('noSuchGroup throws', $threw, true);

// cell() method still works (fallback / dynamic group name)
$WsCalendar->cell('month', 6)->monthName = 'June';
check('cell() method still works', rtrim($WsCalendar->month[6]->monthName), 'June');

// -------------------------------------------------------------------
// 8. Nested OCCURS — WsGrid: gridRow[3] x gridCol[4], fields at every level
//
// Record layout (119 bytes):
//   gridId[8] | gridRow[1..3] (35 B each) | gridFooter[6]
//   gridRow cell: rowLabel[4] | gridCol[1..4] (7 B each) | rowSum[3]
//   gridCol cell: colLabel[3] | cellValue[4]
// -------------------------------------------------------------------
echo "\n============================================================\n";
echo "  8. Nested OCCURS\n";
echo "============================================================\n";

$WsGrid = $levels['WsGrid'];

// Top-level fields (outside any OCCURS)
$WsGrid->gridId     = 'GRID-001';
$WsGrid->gridFooter = 'DONE';
check('gridId',     rtrim($WsGrid->gridId),     'GRID-001');
check('gridFooter', rtrim($WsGrid->gridFooter), 'DONE');

// Outer OCCURS fields (rowLabel, rowSum — no inner OCCURS involved)
$WsGrid->gridRow[1]->rowLabel = 'R1';
$WsGrid->gridRow[2]->rowLabel = 'R2';
$WsGrid->gridRow[3]->rowLabel = 'R3';
check('gridRow[1] rowLabel', rtrim($WsGrid->gridRow[1]->rowLabel), 'R1');
check('gridRow[2] rowLabel', rtrim($WsGrid->gridRow[2]->rowLabel), 'R2');
check('gridRow[3] rowLabel', rtrim($WsGrid->gridRow[3]->rowLabel), 'R3');

$WsGrid->gridRow[1]->rowSum = 10;
$WsGrid->gridRow[2]->rowSum = 20;
$WsGrid->gridRow[3]->rowSum = 30;
check('gridRow[1] rowSum', $WsGrid->gridRow[1]->rowSum, 10);
check('gridRow[2] rowSum', $WsGrid->gridRow[2]->rowSum, 20);
check('gridRow[3] rowSum', $WsGrid->gridRow[3]->rowSum, 30);

// Inner OCCURS fields accessed via nested [row][col] chain
$WsGrid->gridRow[1]->gridCol[1]->colLabel  = 'A';
$WsGrid->gridRow[1]->gridCol[1]->cellValue = 11;
$WsGrid->gridRow[2]->gridCol[3]->colLabel  = 'BC';
$WsGrid->gridRow[2]->gridCol[3]->cellValue = 999;
$WsGrid->gridRow[3]->gridCol[4]->colLabel  = 'Z';
$WsGrid->gridRow[3]->gridCol[4]->cellValue = 42;

check('gridRow[1]->gridCol[1] colLabel',  rtrim($WsGrid->gridRow[1]->gridCol[1]->colLabel),  'A');
check('gridRow[1]->gridCol[1] cellValue', $WsGrid->gridRow[1]->gridCol[1]->cellValue,         11);
check('gridRow[2]->gridCol[3] colLabel',  rtrim($WsGrid->gridRow[2]->gridCol[3]->colLabel),  'BC');
check('gridRow[2]->gridCol[3] cellValue', $WsGrid->gridRow[2]->gridCol[3]->cellValue,         999);
check('gridRow[3]->gridCol[4] colLabel',  rtrim($WsGrid->gridRow[3]->gridCol[4]->colLabel),  'Z');
check('gridRow[3]->gridCol[4] cellValue', $WsGrid->gridRow[3]->gridCol[4]->cellValue,         42);

// Cell independence: different (row,col) pairs must not bleed into each other
check('gridRow[1]->gridCol[2] untouched', $WsGrid->gridRow[1]->gridCol[2]->cellValue, 0);
check('gridRow[2]->gridCol[1] untouched', $WsGrid->gridRow[2]->gridCol[1]->cellValue, 0);

// Top-level fields must survive all OCCURS writes
check('gridId intact after writes',     rtrim($WsGrid->gridId),     'GRID-001');
check('gridFooter intact after writes', rtrim($WsGrid->gridFooter), 'DONE');

// Outer OCCURS fields must survive inner OCCURS writes
check('gridRow[1] rowLabel intact', rtrim($WsGrid->gridRow[1]->rowLabel), 'R1');
check('gridRow[2] rowLabel intact', rtrim($WsGrid->gridRow[2]->rowLabel), 'R2');
check('gridRow[1] rowSum intact',   $WsGrid->gridRow[1]->rowSum,           10);

// rawBytes of an inner cell = exactly entry_size (7) bytes
check('inner cell rawBytes length',
    strlen($WsGrid->gridRow[2]->gridCol[3]), 7);
check('inner cell rawBytes colLabel field',
    substr($WsGrid->gridRow[2]->gridCol[3], 0, 3), 'BC ');

// -------------------------------------------------------------------
// 9. OCCURS DEPENDING ON — WsDynTable
//    Physical buffer holds 5 cells; logical max = $activeRows field.
// -------------------------------------------------------------------
echo "\n============================================================\n";
echo "  9. OCCURS DEPENDING ON\n";
echo "============================================================\n";

$WsDynTable = $levels['WsDynTable'];

// Set activeRows = 3 and write to rows 1..3
$WsDynTable->activeRows = 3;
$WsDynTable->tableFooter = 'FOOTER';
$WsDynTable->dataRow[1]->rowCode  = 'ALPHA';
$WsDynTable->dataRow[1]->rowValue = 100;
$WsDynTable->dataRow[2]->rowCode  = 'BETA';
$WsDynTable->dataRow[2]->rowValue = 200;
$WsDynTable->dataRow[3]->rowCode  = 'GAMMA';
$WsDynTable->dataRow[3]->rowValue = 300;

check('activeRows',           $WsDynTable->activeRows,              3);
check('dataRow[1] rowCode',   rtrim($WsDynTable->dataRow[1]->rowCode),  'ALPHA');
check('dataRow[1] rowValue',  $WsDynTable->dataRow[1]->rowValue,        100);
check('dataRow[2] rowCode',   rtrim($WsDynTable->dataRow[2]->rowCode),  'BETA');
check('dataRow[3] rowValue',  $WsDynTable->dataRow[3]->rowValue,        300);
check('tableFooter intact',   rtrim($WsDynTable->tableFooter),       'FOOTER');

// isset() respects the logical max
check('isset dataRow[3] when activeRows=3', isset($WsDynTable->dataRow[3]), true);
check('isset dataRow[4] when activeRows=3', isset($WsDynTable->dataRow[4]), false);

// Out-of-bounds throws (logical, not physical)
$threw = false;
try { $x = $WsDynTable->dataRow[4]->rowCode; } catch (\Error $e) { $threw = true; }
check('dataRow[4] throws when activeRows=3', $threw, true);

// Raise activeRows to 5 — rows 4 and 5 now accessible
$WsDynTable->activeRows = 5;
$WsDynTable->dataRow[4]->rowCode  = 'DELTA';
$WsDynTable->dataRow[4]->rowValue = 400;
$WsDynTable->dataRow[5]->rowCode  = 'EPSILON';
$WsDynTable->dataRow[5]->rowValue = 500;

check('dataRow[4] accessible after raise', rtrim($WsDynTable->dataRow[4]->rowCode), 'DELTA');
check('dataRow[5] rowValue',               $WsDynTable->dataRow[5]->rowValue,        500);
check('isset dataRow[5] when activeRows=5', isset($WsDynTable->dataRow[5]), true);

// Physical max (5) is always enforced regardless of activeRows
$WsDynTable->activeRows = 5;
$threw = false;
try { $x = $WsDynTable->dataRow[6]->rowCode; } catch (\Error $e) { $threw = true; }
check('dataRow[6] throws even with activeRows=5', $threw, true);

// Earlier rows still intact after raising activeRows
check('dataRow[1] still intact', rtrim($WsDynTable->dataRow[1]->rowCode), 'ALPHA');
check('dataRow[3] still intact', $WsDynTable->dataRow[3]->rowValue,        300);

// -------------------------------------------------------------------
// 10. DECIMAL POINT IS COMMA — European-format edited picture masks
//     wss_comma.phopol sets decimal_point is comma; ',' is decimal,
//     '.' is thousands inserter.
//
//     WsCommaLine layout (26 bytes total):
//       clAmount  edited "ZZZ.ZZ9,99"  offset 0  len 10
//       clCount   edited "ZZ.ZZ9"      offset 10 len 6
//       clLabel   string<10>           offset 16 len 10
// -------------------------------------------------------------------
echo "\n============================================================\n";
echo "  10. DECIMAL POINT IS COMMA\n";
echo "============================================================\n";

$levelsComma = loadSection(__DIR__ . '/../testFFI/wss_comma.phopol');
$WsCommaLine = $levelsComma['WsCommaLine'];

$WsCommaLine->clLabel = 'HELLO';
check('clLabel read-back',  $WsCommaLine->clLabel, 'HELLO     ');

// Amount 12345.67 → " 12.345,67"
$WsCommaLine->clAmount = 12345.67;
check('clAmount=12345.67 raw', substr($WsCommaLine, 0, 10),  ' 12.345,67');

// Amount 0 → "      0,00"
$WsCommaLine->clAmount = 0;
check('clAmount=0 raw',        substr($WsCommaLine, 0, 10),  '      0,00');

// Count 1234 → " 1.234"
$WsCommaLine->clCount = 1234;
check('clCount=1234 raw',      substr($WsCommaLine, 10, 6),  ' 1.234');

// Count 0 → "     0"
$WsCommaLine->clCount = 0;
check('clCount=0 raw',         substr($WsCommaLine, 10, 6),  '     0');

// Label raw bytes
check('clLabel raw',           substr($WsCommaLine, 16, 10), 'HELLO     ');

// -------------------------------------------------------------------
// 11. Standalone fields (level 77)
//     Each standalone becomes a single-field PHoPolLevel01 in $levels.
//     standaloneCtr : int<5> packed  (3 bytes)
//     standaloneFlag: string<1>      (1 byte)
// -------------------------------------------------------------------
echo "\n============================================================\n";
echo "  11. Standalone fields\n";
echo "============================================================\n";

$ctr  = $levels['standaloneCtr'];
$flag = $levels['standaloneFlag'];

$ctr->standaloneCtr   = 99;
$flag->standaloneFlag = 'Y';
check('standaloneCtr write/read',  $ctr->standaloneCtr,   99);
check('standaloneFlag write/read', $flag->standaloneFlag, 'Y');

$ctr->standaloneCtr   = 0;
$flag->standaloneFlag = 'N';
check('standaloneCtr reset to 0',  $ctr->standaloneCtr,   0);
check('standaloneFlag reset to N', $flag->standaloneFlag, 'N');

// Other levels must be unaffected (Section 5 left version=5)
check('WsIdentification intact after standalones', $levels['WsIdentification']->version, 5);

// -------------------------------------------------------------------
// 12. Sub-group REDEFINES — WsPackedRecord
//     prCharView and prBinaryView share the same first 8 bytes.
//     prDescription at byte 8 is independent of both.
// -------------------------------------------------------------------
echo "\n============================================================\n";
echo "  12. Sub-group REDEFINES\n";
echo "============================================================\n";

$WsPackedRecord = $levels['WsPackedRecord'];

// Write through the char view
$WsPackedRecord->prCode        = 'LOCK';
$WsPackedRecord->prSerial      = 5678;
$WsPackedRecord->prDescription = 'Independent field';

check('prCode written',        rtrim($WsPackedRecord->prCode),        'LOCK');
check('prSerial written',      $WsPackedRecord->prSerial,             5678);
check('prDescription written', rtrim($WsPackedRecord->prDescription), 'Independent field');

// Raw bytes at 0-3 = 'LOCK' (display), 4-7 = '5678' (display)
check('bytes 0-3 match prCode',   substr($WsPackedRecord, 0, 4), 'LOCK');
check('bytes 4-7 match prSerial', substr($WsPackedRecord, 4, 4), '5678');

// Write through the binary view and verify the char view changes
$WsPackedRecord->prHighWord = 0;    // zero bytes 0-3
check('prHighWord=0 zeroes bytes 0-3',  substr($WsPackedRecord, 0, 4),        "\x00\x00\x00\x00");
check('prCode reflects binary write',   $WsPackedRecord->prCode,               "\x00\x00\x00\x00");

// bytes 4-7 must be unaffected (prLowWord / prSerial untouched)
check('bytes 4-7 unaffected',  substr($WsPackedRecord, 4, 4), '5678');
check('prSerial still 5678',   $WsPackedRecord->prSerial,     5678);

// prDescription at byte 8 must survive all sub-group writes
check('prDescription independent', rtrim($WsPackedRecord->prDescription), 'Independent field');

// -------------------------------------------------------------------
// 13. Field REDEFINES — WsFieldRedef
//     frDate (uint<8>) and frDateAlpha (string<8>) share 8 bytes.
//     frLabel at byte 8 is independent.
// -------------------------------------------------------------------
echo "\n============================================================\n";
echo "  13. Field REDEFINES\n";
echo "============================================================\n";

$WsFieldRedef = $levels['WsFieldRedef'];

// Write through numeric field, read back as alpha
$WsFieldRedef->frDate = 20260616;
check('frDate written',             $WsFieldRedef->frDate,      20260616);
check('frDateAlpha mirrors frDate', $WsFieldRedef->frDateAlpha, '20260616');

// Write through alpha field (valid digits), read back as numeric
$WsFieldRedef->frDateAlpha = '19991231';
check('frDateAlpha written',               $WsFieldRedef->frDateAlpha, '19991231');
check('frDate mirrors frDateAlpha',        $WsFieldRedef->frDate,      19991231);

// frLabel at offset 8 is independent
$WsFieldRedef->frLabel = 'Christmas';
check('frLabel write/read',                  rtrim($WsFieldRedef->frLabel),    'Christmas');
check('frDate unaffected by frLabel write',  $WsFieldRedef->frDate,            19991231);
check('frDateAlpha unaffected by frLabel',   $WsFieldRedef->frDateAlpha,       '19991231');

// -------------------------------------------------------------------
// 14. Anonymous group REDEFINES — WsWordAlpha
//     An anonymous level(05) redefines $word, exposing each of
//     the 4 bytes as a leaf OCCURS cell ($byte occurs(4)).
// -------------------------------------------------------------------
echo "\n============================================================\n";
echo "  14. Anonymous group REDEFINES\n";
echo "============================================================\n";

$WsWordAlpha = $levels['WsWordAlpha'];

// Write through the flat string field
$WsWordAlpha->word = 'ABCD';
check('word written', $WsWordAlpha->word, 'ABCD');

// Raw buffer confirms all 4 bytes in order
check('raw offset 0 = A', substr($WsWordAlpha, 0, 1), 'A');
check('raw offset 1 = B', substr($WsWordAlpha, 1, 1), 'B');
check('raw offset 2 = C', substr($WsWordAlpha, 2, 1), 'C');
check('raw offset 3 = D', substr($WsWordAlpha, 3, 1), 'D');

// Leaf OCCURS cell access — each cell is 1 byte, read via ->byte sub-field
check('byte[1]->byte = A', $WsWordAlpha->byte[1]->byte, 'A');
check('byte[2]->byte = B', $WsWordAlpha->byte[2]->byte, 'B');
check('byte[3]->byte = C', $WsWordAlpha->byte[3]->byte, 'C');
check('byte[4]->byte = D', $WsWordAlpha->byte[4]->byte, 'D');

// Write through cells and verify $word reflects the change
$WsWordAlpha->byte[1]->byte = 'X';
$WsWordAlpha->byte[4]->byte = 'Z';
check('word after cell writes', $WsWordAlpha->word, 'XBCZ');

// Boundary: out-of-range cell throws
$threw = false;
try { $x = $WsWordAlpha->byte[5]->byte; } catch (\Error $e) { $threw = true; }
check('byte[5] throws', $threw, true);

// -------------------------------------------------------------------
echo "\n------------------------------------------------------------\n";
echo "  $pass passed, $fail failed\n";
echo "============================================================\n";
