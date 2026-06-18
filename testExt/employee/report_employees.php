<?php

//================================================================
// report_employees.php
//
// Reads the binary employee file built by create_employees.php,
// attaches each record to WsEmployee, then formats and MOVEs
// field values into the WsReportLine fields.
//
// Edited picture fields (rlSalary, rlBonus, rlHireDate, rlGrade, rlScore)
// format numeric values automatically via their edit mask, exactly as COBOL
// applies a picture mask on MOVE.  88-level conditions work natively too.
//
//   php -d extension=php_phopol.dll testExt/employee/report_employees.php
//================================================================

// declare(strict_types=1) is intentionally omitted: see create_employees.php for details.
require __DIR__ . '/../../ext/loadSection.php';

use function PHoPol\loadSection;

$levels       = loadSection(__DIR__ . '/../../testFFI/wss_simple.phopol');
$WsEmployee   = $levels['WsEmployee'];
$WsReportLine = $levels['WsReportLine'];

$recSize  = strlen($WsEmployee->rawBytes());   // 87 bytes per employee record
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

    $WsEmployee->attach($raw);

    $WsReportLine->rlId       = $WsEmployee->employeeId;
    $WsReportLine->rlName     = $WsEmployee->employeeName;
    $WsReportLine->rlSalary   = $WsEmployee->baseSalary;						// all edited numeric format are automatically managed
    $WsReportLine->rlBonus    = $WsEmployee->bonus;
    $WsReportLine->rlHireDate = $WsEmployee->hireDate;
    $WsReportLine->rlGrade    = $WsEmployee->payGrade;
    $WsReportLine->rlScore    = $WsEmployee->performanceScore;
    $WsReportLine->rlMarker   = $WsEmployee->isTerminated ? '*' : ' ';			// level 88 boolean is computed as in wss

    echo $WsReportLine . "\n";

    $lineCount++;
    $totalBase  += $WsEmployee->baseSalary;
    $totalBonus += $WsEmployee->bonus;
}

fclose($fh);

//----------------------------------------------------------------
// Totals line — reuse the report level
//----------------------------------------------------------------
$WsReportLine->rlId       = 'TOTALS';
$WsReportLine->rlName     = '';
$WsReportLine->rlSalary   = $totalBase;
$WsReportLine->rlBonus    = $totalBonus;
$WsReportLine->rlHireDate = '';
$WsReportLine->rlGrade    = '';
$WsReportLine->rlScore    = '';
$WsReportLine->rlMarker   = ' ';

echo $sep . "\n";
echo $WsReportLine . "  $lineCount employees\n";
echo "\n* = terminated\n";
