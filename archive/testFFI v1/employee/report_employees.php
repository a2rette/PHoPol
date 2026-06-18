<?php

//================================================================
// report_employees.php
//
// Reads the binary employee file built by create_employees.php,
// attaches each record to WsEmployee, then MOVEs field values
// directly into the WsReportLine edited picture fields —
// the mask is applied automatically on set(), just as COBOL
// applies the picture mask on MOVE TO an edited field.
//
//   php testFFI/report_employees.php
//================================================================

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use function CobPhp\bootstrap;

$wss = bootstrap(__DIR__ . '/../wss_simple.cobphp');

$emp = $wss->getRecord('WsEmployee');
$rpt = $wss->getRecord('WsReportLine');

$recSize  = $emp->layout->totalLength;   // 87 bytes per record
$dataFile = __DIR__ . '/employees.dat';

if (!file_exists($dataFile)) {
    die("employees.dat not found — run create_employees.php first\n");
}

$fh = fopen($dataFile, 'rb');
if ($fh === false) {
    die("Cannot open $dataFile\n");
}

//----------------------------------------------------------------
// Report header
//----------------------------------------------------------------
$hdr = sprintf(
    '%-6s %-30s %14s %10s %10s %2s %6s  ',
    'ID', 'Name', 'Salary', 'Bonus', 'Hired', 'Gr', 'Score'
);
$sep = str_repeat('-', strlen($hdr));

echo $hdr . "\n" . $sep . "\n";

//----------------------------------------------------------------
// Detail rows
//----------------------------------------------------------------
$lineCount  = 0;
$totalBase  = 0.0;
$totalBonus = 0.0;

while (($raw = fread($fh, $recSize)) !== false && strlen($raw) === $recSize) {

    $emp->attach($raw);

    // MOVE corresponding source fields into the report line.
    // Numeric values going into edited<"..."> fields are formatted
    // automatically by set() — no explicit edit_format() needed.
    $rpt->set('rlId',       $emp->get('employeeId'));
    $rpt->set('rlName',     $emp->get('employeeName'));
    $rpt->set('rlSalary',   $emp->get('baseSalary'));
    $rpt->set('rlBonus',    $emp->get('bonus'));
    $rpt->set('rlHireDate', $emp->get('hireDate'));
    $rpt->set('rlGrade',    $emp->get('payGrade'));
    $rpt->set('rlScore',    $emp->get('performanceScore'));
    $rpt->set('rlMarker',   $emp->isCond('isTerminated') ? '*' : ' ');

    echo $rpt->rawBytes() . "\n";

    $lineCount++;
    $totalBase  += $emp->get('baseSalary');
    $totalBonus += $emp->get('bonus');
}

fclose($fh);

//----------------------------------------------------------------
// Totals line — reuse the report record
//----------------------------------------------------------------
$rpt->set('rlId',       'TOTALS');
$rpt->set('rlName',     '');
$rpt->set('rlSalary',   $totalBase);
$rpt->set('rlBonus',    $totalBonus);
$rpt->set('rlHireDate', 0);
$rpt->set('rlGrade',    0);
$rpt->set('rlScore',    0.0);
$rpt->set('rlMarker',   ' ');

echo $sep . "\n";
echo $rpt->rawBytes() . "  $lineCount employees\n";
echo "\n* = terminated\n";
