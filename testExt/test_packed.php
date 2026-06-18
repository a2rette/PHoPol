<?php
// php.exe -d extension=php_phopol.dll -d error_reporting=E_ALL -d display_errors=1 test_packed.php

// -----------------------------------------------------------------------
// Layout with signed and unsigned packed fields, with and without decimals
// -----------------------------------------------------------------------
phopol_register_layout('WsNumeric', 17, [
    // sdecimal<7,2> packed  → digits=7, decimals=2, length=5
    ['name' => 'packedAmount',   'offset' =>  0, 'length' => 5,
     'type' => PHOPOL_TYPE_PACKED, 'digits' => 7, 'decimals' => 2,
     'flags' => PHOPOL_FLAG_SIGNED],
    // sdecimal<9,2> packed  → digits=9, decimals=2, length=6
    ['name' => 'packedBonus',    'offset' =>  5, 'length' => 6,
     'type' => PHOPOL_TYPE_PACKED, 'digits' => 9, 'decimals' => 2,
     'flags' => PHOPOL_FLAG_SIGNED],
    // int<5> packed (no decimals) → digits=5, decimals=0, length=3
    ['name' => 'packedCounter',  'offset' => 11, 'length' => 3,
     'type' => PHOPOL_TYPE_PACKED, 'digits' => 5, 'decimals' => 0,
     'flags' => PHOPOL_FLAG_SIGNED],
    // uint<5> packed (unsigned) → digits=5, decimals=0, length=3
    ['name' => 'packedUnsigned', 'offset' => 14, 'length' => 3,
     'type' => PHOPOL_TYPE_PACKED, 'digits' => 5, 'decimals' => 0,
     'flags' => 0],
]);

$rec = new PHoPolLevel01('WsNumeric');
$rec->allocate();

$pass = 0;
$fail = 0;
function check(string $label, mixed $got, mixed $expected): void {
    global $pass, $fail;
    if (abs((float)$got - (float)$expected) < 0.0001) {
        echo "  OK   $label\n";
        $pass++;
    } else {
        echo "  FAIL $label: got=$got expected=$expected\n";
        $fail++;
    }
}

echo "============================================================\n";
echo "  PACKED DECIMAL (COMP-3) — C extension test\n";
echo "============================================================\n";

// --- positive decimal ---
$rec->packedAmount = 12345.67;
check('positive sdecimal<7,2>', $rec->packedAmount, 12345.67);

// --- negative decimal ---
$rec->packedAmount = -12345.67;
check('negative sdecimal<7,2>', $rec->packedAmount, -12345.67);

// --- zero ---
$rec->packedAmount = 0.0;
check('zero sdecimal<7,2>', $rec->packedAmount, 0.0);

// --- small value ---
$rec->packedAmount = 0.01;
check('small sdecimal<7,2> (0.01)', $rec->packedAmount, 0.01);

// --- large value fits in 7 integer digits ---
$rec->packedAmount = 9999999.99;
check('max sdecimal<7,2> (9999999.99)', $rec->packedAmount, 9999999.99);

// --- larger field sdecimal<9,2> ---
$rec->packedBonus = 123456789.99;
check('positive sdecimal<9,2>', $rec->packedBonus, 123456789.99);

$rec->packedBonus = -987654321.00;
check('negative sdecimal<9,2>', $rec->packedBonus, -987654321.00);

// --- integer packed (no decimals) ---
$rec->packedCounter = 12345;
check('positive int<5> packed', $rec->packedCounter, 12345);

$rec->packedCounter = -99999;
check('negative int<5> packed', $rec->packedCounter, -99999);

$rec->packedCounter = 0;
check('zero int<5> packed', $rec->packedCounter, 0);

// --- unsigned packed (0x0C sign nibble, not 0x0D) ---
$rec->packedUnsigned = 42000;
check('unsigned packed<5>', $rec->packedUnsigned, 42000);

// --- rawBytes round-trip: write then re-read from bytes ---
$rec->packedAmount  = 54321.09;
$rec->packedBonus   = 111111111.11;
$rec->packedCounter = -42;
$raw = $rec->rawBytes();

$rec2 = new PHoPolLevel01('WsNumeric');
$rec2->attach($raw);
check('round-trip packedAmount',  $rec2->packedAmount,  54321.09);
check('round-trip packedBonus',   $rec2->packedBonus,   111111111.11);
check('round-trip packedCounter', $rec2->packedCounter, -42);

// --- independence: verify fields don't bleed into each other ---
$rec->packedAmount  = 111.11;
$rec->packedBonus   = 222.22;
$rec->packedCounter = 333;
check('independence packedAmount',  $rec->packedAmount,  111.11);
check('independence packedBonus',   $rec->packedBonus,   222.22);
check('independence packedCounter', $rec->packedCounter, 333);

echo "------------------------------------------------------------\n";
echo "  $pass passed, $fail failed\n";
echo "============================================================\n";
