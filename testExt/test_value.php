<?php
// php.exe -d extension=php_phopol.dll -d error_reporting=E_ALL -d display_errors=1 test_value.php
//
// Tests VALUE clause initialisation: values applied automatically by allocate().

$pass = 0;
$fail = 0;

function check(string $label, mixed $got, mixed $expected): void {
    global $pass, $fail;
    $ok = $got === $expected;
    if ($ok) {
        echo "  OK   $label\n";
        $pass++;
    } else {
        echo "  FAIL $label: got=" . var_export($got, true)
           . " expected=" . var_export($expected, true) . "\n";
        $fail++;
    }
}

// ===================================================================
// Layout with every VALUE variant in one record:
//
//  offset  len  field          VALUE
//  0       10   alphaHello     = "HELLO"
//  10       5   numCount       = 42
//  15       4   binId          = 1000   (binary signed int32)
//  19       2   packedAmt      = 99     (packed decimal)
//  21      10   fillSpaces     = SPACES (fill — redundant but tested)
//  31       3   fillHigh       = HIGH_VALUES
//  34       3   fillLow        = LOW_VALUES
//  37       5   numZero        = ZERO
//  42       3   quoteField     = QUOTE
//  45       6   negAmount      = -75    (signed display, 6 digits)
//  51      total = 51
// ===================================================================
echo "============================================================\n";
echo "  1. Scalar VALUE clauses\n";
echo "============================================================\n";

phopol_register_layout('WsValue', 51, [
    ['name'=>'alphaHello', 'offset'=> 0, 'length'=>10,
     'type'=>PHOPOL_TYPE_DISPLAY, 'digits'=>0, 'decimals'=>0, 'flags'=>0,
     'initialValue'=>'HELLO', 'initialIsFill'=>false],

    ['name'=>'numCount',   'offset'=>10, 'length'=> 5,
     'type'=>PHOPOL_TYPE_DISPLAY, 'digits'=>5, 'decimals'=>0, 'flags'=>0,
     'initialValue'=>'42', 'initialIsFill'=>false],

    ['name'=>'binId',      'offset'=>15, 'length'=> 4,
     'type'=>PHOPOL_TYPE_BINARY,  'digits'=>0, 'decimals'=>0,
     'flags'=>PHOPOL_FLAG_SIGNED,
     'initialValue'=>'1000', 'initialIsFill'=>false],

    ['name'=>'packedAmt',  'offset'=>19, 'length'=> 2,
     'type'=>PHOPOL_TYPE_PACKED,  'digits'=>3, 'decimals'=>0, 'flags'=>0,
     'initialValue'=>'99', 'initialIsFill'=>false],

    ['name'=>'fillSpaces', 'offset'=>21, 'length'=>10,
     'type'=>PHOPOL_TYPE_DISPLAY, 'digits'=>0, 'decimals'=>0, 'flags'=>0,
     'initialValue'=>' ', 'initialIsFill'=>true],

    ['name'=>'fillHigh',   'offset'=>31, 'length'=> 3,
     'type'=>PHOPOL_TYPE_DISPLAY, 'digits'=>0, 'decimals'=>0, 'flags'=>0,
     'initialValue'=>"\xFF", 'initialIsFill'=>true],

    ['name'=>'fillLow',    'offset'=>34, 'length'=> 3,
     'type'=>PHOPOL_TYPE_DISPLAY, 'digits'=>0, 'decimals'=>0, 'flags'=>0,
     'initialValue'=>"\x00", 'initialIsFill'=>true],

    ['name'=>'numZero',    'offset'=>37, 'length'=> 5,
     'type'=>PHOPOL_TYPE_DISPLAY, 'digits'=>5, 'decimals'=>0, 'flags'=>0,
     'initialValue'=>'0', 'initialIsFill'=>false],

    ['name'=>'quoteField', 'offset'=>42, 'length'=> 3,
     'type'=>PHOPOL_TYPE_DISPLAY, 'digits'=>0, 'decimals'=>0, 'flags'=>0,
     'initialValue'=>'"', 'initialIsFill'=>false],

    ['name'=>'negAmount',  'offset'=>45, 'length'=> 6,
     'type'=>PHOPOL_TYPE_DISPLAY, 'digits'=>5, 'decimals'=>0,
     'flags'=>PHOPOL_FLAG_SIGNED,
     'initialValue'=>'-75', 'initialIsFill'=>false],
]);

$lv = new PHoPolLevel01('WsValue');
$lv->allocate();

check('string VALUE "HELLO"',    $lv->alphaHello,  'HELLO     ');
check('numeric VALUE 42',        $lv->numCount,     42);
check('binary VALUE 1000',       $lv->binId,        1000);
check('packed VALUE 99',         $lv->packedAmt,    99);
check('SPACES fill (10 bytes)',  $lv->fillSpaces,  '          ');
check('HIGH_VALUES fill',        $lv->fillHigh,    "\xFF\xFF\xFF");
check('LOW_VALUES fill',         $lv->fillLow,     "\x00\x00\x00");
check('ZERO on numeric',         $lv->numZero,      0);
check('QUOTE VALUE',             $lv->quoteField,  '"  ');
check('negative VALUE -75',      $lv->negAmount,   -75);

// ===================================================================
// Fields without VALUE: COBOL defaults applied by allocate()
//   alpha display  → spaces
//   numeric display, binary, packed, float, php int/float → ZERO
//   php string     → '' (NULL pointer)
// ===================================================================
echo "\n============================================================\n";
echo "  2. No VALUE clause → COBOL defaults\n";
echo "============================================================\n";

//  offset  len  field
//   0       5   alphaField   DISPLAY alpha
//   5       3   numField     DISPLAY numeric
//   8       4   binField     BINARY signed int32
//  12       2   packedField  PACKED decimal (3 digits)
//  14       8   floatField   FLOAT64
//  22       8   phpLong      PHP_LONG
//  30       8   phpDouble    PHP_DOUBLE
//  38       8   phpStr       PHP_STRING
//  total = 46
phopol_register_layout('WsNoValue', 46, [
    ['name'=>'alphaField',  'offset'=> 0, 'length'=>5,
     'type'=>PHOPOL_TYPE_DISPLAY,    'digits'=>0, 'decimals'=>0, 'flags'=>0],
    ['name'=>'numField',    'offset'=> 5, 'length'=>3,
     'type'=>PHOPOL_TYPE_DISPLAY,    'digits'=>3, 'decimals'=>0, 'flags'=>0],
    ['name'=>'binField',    'offset'=> 8, 'length'=>4,
     'type'=>PHOPOL_TYPE_BINARY,     'digits'=>0, 'decimals'=>0, 'flags'=>PHOPOL_FLAG_SIGNED],
    ['name'=>'packedField', 'offset'=>12, 'length'=>2,
     'type'=>PHOPOL_TYPE_PACKED,     'digits'=>3, 'decimals'=>0, 'flags'=>0],
    ['name'=>'floatField',  'offset'=>14, 'length'=>8,
     'type'=>PHOPOL_TYPE_FLOAT64,    'digits'=>0, 'decimals'=>0, 'flags'=>0],
    ['name'=>'phpLong',     'offset'=>22, 'length'=>8,
     'type'=>PHOPOL_TYPE_PHP_LONG,   'digits'=>0, 'decimals'=>0, 'flags'=>0],
    ['name'=>'phpDouble',   'offset'=>30, 'length'=>8,
     'type'=>PHOPOL_TYPE_PHP_DOUBLE, 'digits'=>0, 'decimals'=>0, 'flags'=>0],
    ['name'=>'phpStr',      'offset'=>38, 'length'=>8,
     'type'=>PHOPOL_TYPE_PHP_STRING, 'digits'=>0, 'decimals'=>0, 'flags'=>0],
]);

$nv = new PHoPolLevel01('WsNoValue');
$nv->allocate();

check('alpha no VALUE → spaces',      $nv->alphaField,  '     ');
check('num display no VALUE → 0',     $nv->numField,     0);
check('binary no VALUE → 0',          $nv->binField,     0);
check('packed no VALUE → 0',          $nv->packedField,  0);
check('float64 no VALUE → 0.0',       $nv->floatField,   0.0);
check('php int no VALUE → 0',         $nv->phpLong,      0);
check('php float no VALUE → 0.0',     $nv->phpDouble,    0.0);
check('php string no VALUE → ""',     $nv->phpStr,       '');

// ===================================================================
// VALUE is re-applied on each fresh allocate()
// ===================================================================
echo "\n============================================================\n";
echo "  3. VALUE re-applied on each allocate()\n";
echo "============================================================\n";

$lv2 = new PHoPolLevel01('WsValue');
$lv2->allocate();
$lv2->numCount = 999;
check('after manual write: numCount=999', $lv2->numCount, 999);

// Second object: starts fresh — VALUE must be applied again
$lv3 = new PHoPolLevel01('WsValue');
$lv3->allocate();
check('new allocate restores VALUE 42',   $lv3->numCount,  42);

// ===================================================================
// OCCURS with VALUE
// ===================================================================
echo "\n============================================================\n";
echo "  4. OCCURS with VALUE (fill)\n";
echo "============================================================\n";

// 4 cells × 3 bytes each, each cell has a single 3-byte alpha sub-field
// initialised with HIGH_VALUES fill
phopol_register_layout('WsOccVal', 12, [
    ['name'=>'slot[*]_code', 'offset'=>0, 'length'=>3,
     'type'=>PHOPOL_TYPE_DISPLAY, 'digits'=>0, 'decimals'=>0, 'flags'=>0,
     'occursMax'=>4, 'entrySize'=>3,
     'initialValue'=>"\xFF", 'initialIsFill'=>true],
]);

$ov = new PHoPolLevel01('WsOccVal');
$ov->allocate();

for ($i = 1; $i <= 4; $i++) {
    check("slot[$i]->code = HIGH_VALUES", $ov->slot[$i]->code, "\xFF\xFF\xFF");
}

// ===================================================================
// 5. Integration: ext/PHoPolParser → bootstrap() → phopol_register_layout()
//
// Uses the new extension-side parser (ext/PHoPolParser.php) which outputs
// plain arrays directly compatible with phopol_register_layout() — no
// PHoPolField / PHoPolLayout objects, no flag-bitmask conversion step.
// ===================================================================
echo "\n============================================================\n";
echo "  5. Integration: ext/bootstrap() -> phopol_register_layout()\n";
echo "============================================================\n";

require_once __DIR__ . '/../ext/loadSection.php'; // also loads ext/PHoPolParser.php

$levels = PHoPol\loadSection(__DIR__ . '/../testFFI/wss_value.phopol');

$WsScalarVal = $levels['WsScalarVal'];
check('parser: alphaHello = HELLO (space-padded)', $WsScalarVal->alphaHello, 'HELLO     ');
check('parser: numCount = 42',                     $WsScalarVal->numCount,   42);
check('parser: negAmount = -75',                   $WsScalarVal->negAmount,  -75);
check('parser: numZero = 0',                       $WsScalarVal->numZero,    0);
check('parser: fillSpaces = 10 spaces',            $WsScalarVal->fillSpaces, '          ');
check('parser: fillHigh = 3× 0xFF',               $WsScalarVal->fillHigh,   "\xFF\xFF\xFF");
check('parser: fillLow = 3× 0x00',                $WsScalarVal->fillLow,    "\x00\x00\x00");
check('parser: quoteField = "  ',                  $WsScalarVal->quoteField, '"  ');

$WsOccurVal = $levels['WsOccurVal'];
for ($i = 1; $i <= 4; $i++) {
    check("parser: slot[$i]->code = HIGH_VALUES", $WsOccurVal->slot[$i]->code, "\xFF\xFF\xFF");
}

$WsNoVal = $levels['WsNoVal'];
check('parser: alpha no VALUE → spaces', $WsNoVal->alpha, '        ');
check('parser: num no VALUE → 0',        $WsNoVal->num,   0);

// ===================================================================
echo "\n------------------------------------------------------------\n";
echo "  $pass passed, $fail failed\n";
echo "============================================================\n";
