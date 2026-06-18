<?php

//================================================================
// generate_data.php — create bench_input.dat for benchmarks
//
// Writes N transaction records using PHoPol so the binary layout
// matches what bench_phopol.php and bench_pure_php.php expect.
//
//   php -d extension=php_phopol.dll testPerf/generate_data.php [N]
//
// Default N = 50 000.  Output: testPerf/bench_input.dat
//================================================================

require __DIR__ . '/../../ext/loadSection.php';

use function PHoPol\loadSection;

$n = isset($argv[1]) ? max(1, (int)$argv[1]) : 50_000;

$levels = loadSection(__DIR__ . '/wss_perf.phopol');
$In     = $levels['WsBenchInput'];

$outPath = __DIR__ . '/bench_input.dat';
$fh      = fopen($outPath, 'wb');
if ($fh === false) {
    die("Cannot open $outPath for writing\n");
}

$recSize = strlen($In->rawBytes());

$codes     = ['PROD', 'SERV', 'CONS', 'PART'];
$basePrice = 9.99;
$priceStep = 1.95;   // 500 distinct unit-price levels

$t0 = microtime(true);

for ($i = 1; $i <= $n; $i++) {
    $qty       = ($i % 100) + 1;                                 // 1 .. 100
    $unitPrice = round($basePrice + ($i % 500) * $priceStep, 2); // 9.99 .. 983.04
    $amount    = round($qty * $unitPrice, 2);

    $In->txId        = 'TX' . str_pad($i, 6, '0', STR_PAD_LEFT);
    $In->txDate      = 20240101 + ($i % 366);
    $In->txCode      = $codes[$i % 4];
    $In->txQty       = $qty;
    $In->txUnitPrice = $unitPrice;
    $In->txAmount    = $amount;

    fwrite($fh, $In);
}

fclose($fh);
$elapsed = (microtime(true) - $t0) * 1000;
$bytes   = $n * $recSize;

printf(
    "Generated %d records × %d bytes = %s\nFile : %s\nTime : %.1f ms\n",
    $n, $recSize, number_format($bytes) . ' bytes',
    $outPath,
    $elapsed
);
