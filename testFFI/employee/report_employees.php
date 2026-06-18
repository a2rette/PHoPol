<?php

//================================================================
// report_employees.php
//
// Reads the binary employee file built by create_employees.php,
// attaches each record to WsEmployee, then MOVEs field values
// directly into the WsReportLine edited picture fields â€”
// the mask is applied automatically on set(), just as COBOL
// applies the picture mask on MOVE TO an edited field.
//
//   php testFFI/report_employees.php
//================================================================

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use function PHoPol\bootstrap;

$wss = bootstrap(__DIR__ . '/../wss_simple.phopol');

$WsEmployee = $wss->getLevel('WsEmployee');
$WsReportLine = $wss->getLevel('WsReportLine');

$recSize  = $WsEmployee->layout->totalLength;   // 87 bytes per record
$dataFile = __DIR__ . '/employees.dat';

if (!file_exists($dataFile)) {
    die("employees.dat not found â€” run create_employees.php first\n");
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

    // MOVE corresponding source fields into the report line.
    // Numeric values going into edited<"..."> fields are formatted
    // automatically by set() â€” no explicit edit_format() needed.
    $WsReportLine->set('rlId',       $WsEmployee->get('employeeId'));
    $WsReportLine->set('rlName',     $WsEmployee->get('employeeName'));
    $WsReportLine->set('rlSalary',   $WsEmployee->get('baseSalary'));
    $WsReportLine->set('rlBonus',    $WsEmployee->get('bonus'));
    $WsReportLine->set('rlHireDate', $WsEmployee->get('hireDate'));
    $WsReportLine->set('rlGrade',    $WsEmployee->get('payGrade'));
    $WsReportLine->set('rlScore',    $WsEmployee->get('performanceScore'));
    $WsReportLine->set('rlMarker',   $WsEmployee->isCond('isTerminated') ? '*' : ' ');

    echo $WsReportLine->rawBytes() . "\n";

    $lineCount++;
    $totalBase  += $WsEmployee->get('baseSalary');
    $totalBonus += $WsEmployee->get('bonus');
}

fclose($fh);

//----------------------------------------------------------------
// Totals line â€” reuse the report record
//----------------------------------------------------------------
$WsReportLine->set('rlId',       'TOTALS');
$WsReportLine->set('rlName',     '');
$WsReportLine->set('rlSalary',   $totalBase);
$WsReportLine->set('rlBonus',    $totalBonus);
$WsReportLine->set('rlHireDate', 0);
$WsReportLine->set('rlGrade',    0);
$WsReportLine->set('rlScore',    0.0);
$WsReportLine->set('rlMarker',   ' ');

echo $sep . "\n";
echo $WsReportLine->rawBytes() . "  $lineCount employees\n";
echo "\n* = terminated\n";
