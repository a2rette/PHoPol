<?php

//================================================================
// bench_phopol.php — in-memory OCCURS table via PHoPol C extension
//
//   php -d extension=php_phopol.dll testPerf/ArrayOp/bench_phopol.php [N]
//
// Phase 1 — Populate : write itemId, category, qty, price for
//            every row via $Table->item[$i]->field = value.
// Phase 2 — Compute  : read qty/price/category, compute amount /
//            discountRate / netAmount, write back, accumulate
//            aggregates.
//
// Default N = 10 000.  Max N = 50 000 (defined in wss_array.phopol).
//================================================================

require __DIR__ . '/../../ext/loadSection.php';

use function PHoPol\loadSection;

$n = isset($argv[1]) ? max(1, min(50_000, (int)$argv[1])) : 10_000;

// ----------------------------------------------------------------
// Setup
// ----------------------------------------------------------------
$tLoad0 = microtime(true);
$levels = loadSection(__DIR__ . '/wss_array.phopol');
$tLoad  = (microtime(true) - $tLoad0) * 1000;

$Table = $levels['WsBenchTable'];
$Table->rowCount = $n;

$categories    = ['ELEC', 'FOOD', 'CLTH', 'HOME'];
$discountRates = ['ELEC' => 0.10, 'FOOD' => 0.05, 'CLTH' => 0.15, 'HOME' => 0.08];

// ----------------------------------------------------------------
// Phase 1 — Populate
// ----------------------------------------------------------------
$t1 = microtime(true);

for ($i = 1; $i <= $n; $i++) {
    $row = $Table->item[$i];
    $row->itemId   = 'ITEM' . str_pad($i, 4, '0', STR_PAD_LEFT);
    $row->category = $categories[$i % 4];
    $row->qty      = ($i % 200) + 1;
    $row->price    = 5.0 + ($i % 100) * 2.5;
    unset($row);		// 9% elapsed gain on phase 1, due to Fast path in phopol_occurs_group_read_dimension
}

$tPopulate = (microtime(true) - $t1) * 1000;

// ----------------------------------------------------------------
// Phase 2 — Compute + Aggregate
// ----------------------------------------------------------------
$sumAmount = 0.0;
$sumNet    = 0.0;
$countHigh = 0;
$maxPrice  = 0.0;

$t2 = microtime(true);

for ($i = 1; $i <= $n; $i++) {
    $row = $Table->item[$i];

    $qty   = $row->qty;
    $price = $row->price;

    $amount       = $qty * $price;
    $discountRate = $discountRates[$row->category];
    $netAmount    = $amount * (1.0 - $discountRate);

    $row->amount       = $amount;
    $row->discountRate = $discountRate;
    $row->netAmount    = $netAmount;

    $sumAmount += $amount;
    $sumNet    += $netAmount;
    if ($netAmount > 5000.0) $countHigh++;
    if ($price > $maxPrice)  $maxPrice = $price;
    unset($row);		// 5% elapsed gain on phase 2, due to Fast path in phopol_occurs_group_read_dimension
}

$tCompute = (microtime(true) - $t2) * 1000;

$peakMB = memory_get_peak_usage(true) / 1_048_576;

// ----------------------------------------------------------------
// Report
// ----------------------------------------------------------------
printf("%-22s %s\n",   'Implementation:',    'PHoPol C extension');
printf("%-22s %d\n",   'Rows:',              $n);
printf("%-22s %.2f ms\n", 'loadSection():',  $tLoad);
printf("%-22s %.2f ms\n", 'Phase 1 populate:', $tPopulate);
printf("%-22s %.2f ms\n", 'Phase 2 compute:',  $tCompute);
printf("%-22s %.2f ms\n", 'Total (1+2):',    $tPopulate + $tCompute);
printf("%-22s %.2f MB\n", 'Peak PHP memory:', $peakMB);
printf("%-22s (C-side buffer ~%.1f MB not counted)\n", '',
    (4 + $n * 48) / 1_048_576);
printf("\nAggregates (checksum):\n");
printf("  sumAmount  : %s\n",  number_format($sumAmount,  2));
printf("  sumNet     : %s\n",  number_format($sumNet,     2));
printf("  countHigh  : %d\n",  $countHigh);
printf("  maxPrice   : %.2f\n", $maxPrice);
