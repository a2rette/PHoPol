<?php

//================================================================
// bench_pure_php.php — process bench_input.dat with plain PHP
//
//   php testPerf/bench_pure_php.php
//
// Performs exactly the same task as bench_phopol.php but uses
// raw PHP string operations instead of the C extension:
//   - substr / str_pad for display and string fields
//   - pack / unpack for machine-native binary integers
//   - custom BCD encode/decode for packed decimal (COMP-3)
//
// NOTE on binary-integer byte order:
//   pack('l', ...) / unpack('l', ...) use machine-native byte order
//   (little-endian on Windows x86-64).  The PHoPol C extension is
//   assumed to follow the same convention on this platform.  If
//   running on a big-endian host, change every 'l' to 'l>' and 'L'
//   to 'N' in the intPack / intUnpack helpers below.
//================================================================

// ----------------------------------------------------------------
// Record layout constants (must match wss_perf.phopol)
// ----------------------------------------------------------------
const IN_OFF_ID        =  0;  // string<8>
const IN_OFF_DATE      =  8;  // uint<8>   display
const IN_OFF_CODE      = 16;  // string<4>
const IN_OFF_QTY       = 20;  // int<9> binary  → 4 bytes
const IN_OFF_UNITPRICE = 24;  // sdecimal<9,2>  packed  → 6 bytes
const IN_OFF_AMOUNT    = 30;  // sdecimal<11,2> packed  → 7 bytes
const IN_REC_SIZE      = 37;

const OUT_OFF_ID        =  0;
const OUT_OFF_DATE      =  8;
const OUT_OFF_CODE      = 16;
const OUT_OFF_QTY       = 20;
const OUT_OFF_UNITPRICE = 24;
const OUT_OFF_AMOUNT    = 30;
const OUT_OFF_GROSS     = 37;
const OUT_OFF_TAX       = 44;
const OUT_OFF_NET       = 50;
const OUT_OFF_STATUS    = 57;
const OUT_REC_SIZE      = 59;

// ----------------------------------------------------------------
// Encode / decode helpers
// ----------------------------------------------------------------

// string<N>: space-padded, right-trimmed on read
function strUnpack(string $raw, int $off, int $n): string
{
    return rtrim(substr($raw, $off, $n));
}
function strPack(string $v, int $n): string
{
    return str_pad(substr($v, 0, $n), $n);
}

// uint<N> display: zero-padded ASCII digit string
function uint8Unpack(string $raw, int $off): int
{
    return (int)substr($raw, $off, 8);
}
function uint8Pack(int $v): string
{
    return str_pad((string)$v, 8, '0', STR_PAD_LEFT);
}

// int<9> binary: 4-byte signed, machine-native byte order
function intUnpack(string $raw, int $off): int
{
    return unpack('l', substr($raw, $off, 4))[1];
}
function intPack(int $v): string
{
    return pack('l', $v);
}

// Packed decimal (COMP-3) encode
// totalDigits = digits + decimals in the .phopol declaration
// Returns exactly ceil((totalDigits+1)/2) bytes.
function bcdPack(float $value, int $totalDigits, int $decimals): string
{
    $neg    = $value < 0;
    $intVal = (int)round(abs($value) * (10 ** $decimals));
    // nibbleTotal is always even; digitSlots = nibbleTotal - 1 (always odd)
    $bytes       = (int)ceil(($totalDigits + 1) / 2);
    $digitSlots  = $bytes * 2 - 1;
    $numStr      = str_pad((string)$intVal, $digitSlots, '0', STR_PAD_LEFT);
    $sign        = $neg ? 0xD : 0xC;
    $result      = '';
    $last        = $digitSlots - 1;
    for ($i = 0; $i < $last; $i += 2) {
        $result .= chr(((int)$numStr[$i] << 4) | (int)$numStr[$i + 1]);
    }
    $result .= chr(((int)$numStr[$last] << 4) | $sign);
    return $result;
}

// Packed decimal (COMP-3) decode
// $bytes: how many physical bytes to read (same as above formula)
function bcdUnpack(string $raw, int $off, int $bytes, int $decimals): float
{
    $intVal = 0;
    $last   = $off + $bytes - 1;
    for ($i = $off; $i < $last; $i++) {
        $b = ord($raw[$i]);
        $intVal = $intVal * 100 + (($b >> 4) & 0xF) * 10 + ($b & 0xF);
    }
    // last byte: high nibble = last digit, low nibble = sign
    $b      = ord($raw[$last]);
    $intVal = $intVal * 10 + (($b >> 4) & 0xF);
    $neg    = ($b & 0xF) === 0xD;
    $result = $intVal / (10 ** $decimals);
    return $neg ? -$result : $result;
}

// ----------------------------------------------------------------
// Main
// ----------------------------------------------------------------
$inPath  = __DIR__ . '/bench_input.dat';
$outPath = __DIR__ . '/bench_output_pure.dat';

if (!file_exists($inPath)) {
    die("bench_input.dat not found — run generate_data.php first\n");
}

$fhIn  = fopen($inPath,  'rb');
$fhOut = fopen($outPath, 'wb');

$nRecs   = 0;
$cAmount = 0.0;
$cGross  = 0.0;
$cTax    = 0.0;
$cNet    = 0.0;

$t0 = microtime(true);

while (($raw = fread($fhIn, IN_REC_SIZE)) !== false && strlen($raw) === IN_REC_SIZE) {

    // -- decode input --
    $id        = strUnpack($raw, IN_OFF_ID, 8);
    $date      = uint8Unpack($raw, IN_OFF_DATE);
    $code      = strUnpack($raw, IN_OFF_CODE, 4);
    $qty       = intUnpack($raw, IN_OFF_QTY);
    $unitPrice = bcdUnpack($raw, IN_OFF_UNITPRICE, 6, 2);
    $amount    = bcdUnpack($raw, IN_OFF_AMOUNT,    7, 2);

    // -- compute --
    $gross  = round($qty * $unitPrice, 2);
    $tax    = round($gross * 0.20,     2);
    $net    = $gross - $tax;
    $status = ($qty > 0 && $unitPrice > 0.0) ? 'OK' : 'ER';

    // -- encode output --
    $out  = strPack($id, 8);
    $out .= uint8Pack($date);
    $out .= strPack($code, 4);
    $out .= intPack($qty);
    $out .= bcdPack($unitPrice, 11, 2);  // sdecimal<9,2>  → totalDigits=11
    $out .= bcdPack($amount,    13, 2);  // sdecimal<11,2> → totalDigits=13
    $out .= bcdPack($gross,     13, 2);
    $out .= bcdPack($tax,       11, 2);  // sdecimal<9,2>  → totalDigits=11
    $out .= bcdPack($net,       13, 2);
    $out .= strPack($status, 2);

    fwrite($fhOut, $out);

    $nRecs++;
    $cAmount += $amount;
    $cGross  += $gross;
    $cTax    += $tax;
    $cNet    += $net;
}

$elapsed = (microtime(true) - $t0) * 1000;

fclose($fhIn);
fclose($fhOut);

$peakMB = memory_get_peak_usage(true) / 1_048_576;

printf("%-20s %s\n",  'Implementation:',  'Pure PHP');
printf("%-20s %d\n",  'Records:',          $nRecs);
printf("%-20s %.1f ms\n", 'Elapsed:',      $elapsed);
printf("%-20s %s rec/s\n", 'Throughput:',
    number_format((int)($nRecs / ($elapsed / 1000))));
printf("%-20s %.2f MB\n", 'Peak PHP memory:',  $peakMB);
printf("\nTotals (checksum):\n");
printf("  sumAmount : %s\n", number_format(round($cAmount, 2), 2));
printf("  sumGross  : %s\n", number_format(round($cGross,  2), 2));
printf("  sumTax    : %s\n", number_format(round($cTax,    2), 2));
printf("  sumNet    : %s\n", number_format(round($cNet,    2), 2));
printf("\nOutput: %s (%s bytes)\n",
    $outPath,
    number_format(filesize($outPath)));
