<?php

//================================================================
// bench_pure_php.php — in-memory array-of-arrays, plain PHP
//
//   php testPerf/ArrayOp/bench_pure_php.php [N]
//
// Mirrors bench_phopol.php exactly: same two phases, same
// computation, same aggregates — but uses a PHP array of
// associative arrays instead of a PHoPol OCCURS table.
//
// Default N = 10 000.
//================================================================

$n = isset($argv[1]) ? max(1, (int)$argv[1]) : 10_000;

$categories    = ['ELEC', 'FOOD', 'CLTH', 'HOME'];
$discountRates = ['ELEC' => 0.10, 'FOOD' => 0.05, 'CLTH' => 0.15, 'HOME' => 0.08];

// ----------------------------------------------------------------
// Phase 1 — Populate
// ----------------------------------------------------------------
$table = [];

$t1 = microtime(true);

for ($i = 1; $i <= $n; $i++) {
    $table[] = [
        'itemId'       => 'ITEM' . str_pad($i, 4, '0', STR_PAD_LEFT),
        'category'     => $categories[$i % 4],
        'qty'          => ($i % 200) + 1,
        'price'        => 5.0 + ($i % 100) * 2.5,
        'amount'       => 0.0,
        'discountRate' => 0.0,
        'netAmount'    => 0.0,
    ];
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

for ($i = 0; $i < $n; $i++) {
    $qty   = $table[$i]['qty'];
    $price = $table[$i]['price'];

    $amount       = $qty * $price;
    $discountRate = $discountRates[$table[$i]['category']];
    $netAmount    = $amount * (1.0 - $discountRate);

    $table[$i]['amount']       = $amount;
    $table[$i]['discountRate'] = $discountRate;
    $table[$i]['netAmount']    = $netAmount;

    $sumAmount += $amount;
    $sumNet    += $netAmount;
    if ($netAmount > 5000.0) $countHigh++;
    if ($price > $maxPrice)  $maxPrice = $price;
}

$tCompute = (microtime(true) - $t2) * 1000;

$peakMB = memory_get_peak_usage(true) / 1_048_576;

// ----------------------------------------------------------------
// Report
// ----------------------------------------------------------------
printf("%-22s %s\n",   'Implementation:',      'Pure PHP');
printf("%-22s %d\n",   'Rows:',                $n);
printf("%-22s %.2f ms\n", 'Phase 1 populate:',  $tPopulate);
printf("%-22s %.2f ms\n", 'Phase 2 compute:',   $tCompute);
printf("%-22s %.2f ms\n", 'Total (1+2):',      $tPopulate + $tCompute);
printf("%-22s %.2f MB\n", 'Peak PHP memory:',   $peakMB);
printf("\nAggregates (checksum):\n");
printf("  sumAmount  : %s\n",  number_format($sumAmount,  2));
printf("  sumNet     : %s\n",  number_format($sumNet,     2));
printf("  countHigh  : %d\n",  $countHigh);
printf("  maxPrice   : %.2f\n", $maxPrice);
