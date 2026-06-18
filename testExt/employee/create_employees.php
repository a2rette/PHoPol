<?php

//================================================================
// create_employees.php
//
// Builds a flat binary file (employees.dat) whose records match
// the WsEmployee layout exactly: packed-decimal salary/bonus/comp,
// binary payGrade, display (zoned decimal / ASCII) for everything
// else.  Run once to generate the test data.
//
//   php -d extension=php_phopol.dll testExt/employee/create_employees.php
//================================================================

// declare(strict_types=1) is intentionally omitted: with strict_types, PHP 8 refuses to
// pass a Stringable object (PHoPolLevel01) to fwrite()'s string parameter, forcing an
// explicit ->rawBytes() call that has no place in migrated COBOL application code.
// Without it, fwrite($fh, $WsEmployee) transparently writes the raw binary record bytes,
// which is the correct semantic equivalent of COBOL's WRITE statement.
require __DIR__ . '/../../ext/loadSection.php';

use function PHoPol\loadSection;

$levels     = loadSection(__DIR__ . '/../../testFFI/wss_simple.phopol');
$WsEmployee = $levels['WsEmployee'];

$outPath = __DIR__ . '/employees.dat';
$fh      = fopen($outPath, 'wb');
if ($fh === false) {
    die("Cannot open $outPath for writing\n");
}

$recSize = strlen($WsEmployee->rawBytes());   // 87 bytes

// Each row: [id, name, gender, status, baseSalary, bonus, totalComp, payGrade, hireDate, reviewDate, yearsService, performanceScore]
$rows = [
    ['E00042', 'John Smith',           'M', 'A', 75000.00,  5000.00,  80000.00,  8, 20150315, 20260101, 11,  4.75],
    ['E00107', 'Marie Dupont',         'F', 'A', 92000.00, 12000.00, 104000.00, 10, 20100622, 20260101, 15,  4.90],
    ['E00203', 'Robert Johnson',       'M', 'R', 45000.00,     0.00,  45000.00,  5, 20190901, 20250101,  6,  3.50],
    ['E00381', 'Sarah Okonkwo',        'F', 'A',120000.00, 25000.00, 145000.00, 12, 20051115, 20260101, 20,  5.00],
    ['E00512', 'Pierre Martin',        'M', 'T', 63000.00,  3000.00,  66000.00,  7, 20180401, 20240601,  7,  3.20],
    ['E00667', 'Yuki Tanaka',          'F', 'A', 88500.00,  8500.00,  97000.00,  9, 20120710, 20260101, 13,  4.40],
];

foreach ($rows as $row) {
    [$id, $name, $gender, $status, $base, $bonus, $total, $grade, $hire, $review, $years, $score] = $row;

    $WsEmployee->employeeId       = $id;
    $WsEmployee->employeeName     = $name;
    $WsEmployee->employeeGender   = $gender;
    $WsEmployee->employeeStatus   = $status;
    $WsEmployee->baseSalary       = $base;
    $WsEmployee->bonus            = $bonus;
    $WsEmployee->totalComp        = $total;
    $WsEmployee->payGrade         = $grade;
    $WsEmployee->hireDate         = $hire;
    $WsEmployee->reviewDate       = $review;
    $WsEmployee->yearsService     = $years;
    $WsEmployee->performanceScore = $score;

    fwrite($fh, $WsEmployee);
    echo "Written: $id  $name\n";
}

fclose($fh);
echo "\nFile: $outPath  (" . count($rows) . " records x {$recSize} bytes = " . (count($rows) * $recSize) . " bytes)\n";
