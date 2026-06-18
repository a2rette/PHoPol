<?php

//================================================================
// bench_phopol.php — process bench_input.dat via PHoPol C extension
//
//   php -d extension=php_phopol.dll testPerf/bench_phopol.php
//
// Reads every WsBenchInput record, computes gross / tax / net,
// writes WsBenchOutput to bench_output_phopol.dat, accumulates
// totals into WsBenchTotals.
//
// Run generate_data.php first to create bench_input.dat.
//================================================================

require __DIR__ . '/../../ext/loadSection.php';

use function PHoPol\loadSection;

$inPath  = __DIR__ . '/bench_input.dat';
$outPath = __DIR__ . '/bench_output_phopol.dat';

if (!file_exists($inPath)) {
    die("bench_input.dat not found — run generate_data.php first\n");
}

$tLoad0  = microtime(true);
$levels  = loadSection(__DIR__ . '/wss_perf.phopol');
$tLoad   = (microtime(true) - $tLoad0) * 1000;

$In      = $levels['WsBenchInput'];
$Out     = $levels['WsBenchOutput'];
$Totals  = $levels['WsBenchTotals'];

$inSize  = strlen($In->rawBytes());
$fhIn    = fopen($inPath, 'rb');
$fhOut   = fopen($outPath, 'wb');

// ----------------------------------------------------------------
// PHP-side accumulators (avoid repeated PHoPol read-modify-write)
// ----------------------------------------------------------------
$nRecs   = 0;
$cAmount = 0.0;
$cGross  = 0.0;
$cTax    = 0.0;
$cNet    = 0.0;

$t0 = microtime(true);

while (($raw = fread($fhIn, $inSize)) !== false && strlen($raw) === $inSize) {

    $In->attach($raw);

    $qty       = $In->txQty;
    $unitPrice = $In->txUnitPrice;
    $amount    = $In->txAmount;

    $gross = round($qty * $unitPrice, 2);
    $tax   = round($gross * 0.20,     2);
    $net   = $gross - $tax;

    $Out->outId        = $In->txId;
    $Out->outDate      = $In->txDate;
    $Out->outCode      = $In->txCode;
    $Out->outQty       = $qty;
    $Out->outUnitPrice = $unitPrice;
    $Out->outAmount    = $amount;
    $Out->outGross     = $gross;
    $Out->outTax       = $tax;
    $Out->outNet       = $net;
    $Out->outStatus    = ($qty > 0 && $unitPrice > 0.0) ? 'OK' : 'ER';

    fwrite($fhOut, $Out);

    $nRecs++;
    $cAmount += $amount;
    $cGross  += $gross;
    $cTax    += $tax;
    $cNet    += $net;
}

$elapsed = (microtime(true) - $t0) * 1000;

// Flush totals into the PHoPol record (one write per field at the end)
$Totals->totalRecs = $nRecs;
$Totals->sumAmount = round($cAmount, 2);
$Totals->sumGross  = round($cGross,  2);
$Totals->sumTax    = round($cTax,    2);
$Totals->sumNet    = round($cNet,    2);

fclose($fhIn);
fclose($fhOut);

$peakMB = memory_get_peak_usage(true) / 1_048_576;

printf("%-20s %s\n",  'Implementation:',  'PHoPol C extension');
printf("%-20s %.2f ms\n", 'loadSection():',   $tLoad);
printf("%-20s %d\n",  'Records:',          $nRecs);
printf("%-20s %.1f ms\n", 'Elapsed:',      $elapsed);
printf("%-20s %s rec/s\n", 'Throughput:',
    number_format((int)($nRecs / ($elapsed / 1000))));
printf("%-20s %.2f MB\n", 'Peak PHP memory:',  $peakMB);
printf("%-20s (PHP memory only — C-side buffers not counted)\n", '');
printf("\nTotals (checksum):\n");
printf("  sumAmount : %s\n", number_format($Totals->sumAmount, 2));
printf("  sumGross  : %s\n", number_format($Totals->sumGross,  2));
printf("  sumTax    : %s\n", number_format($Totals->sumTax,    2));
printf("  sumNet    : %s\n", number_format($Totals->sumNet,    2));
printf("\nOutput: %s (%s bytes)\n",
    $outPath,
    number_format(filesize($outPath)));
