<?php
// php testFFI/test_value_parser.php
//
// Tests the parser + bootstrap VALUE clause pipeline end-to-end:
//   1. PHoPolParser::parse() produces correct PHoPolField descriptors
//   2. bootstrap() constructs levels with VALUE-initialised buffers
//   3. attach() overwrites VALUE-initialised bytes (VALUE not re-applied)
//   4. initialize() resets to type defaults, NOT to VALUE clause values

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

use PHoPol\PHoPolParser;
use function PHoPol\bootstrap;
use function PHoPol\initialize;

$pass = 0;
$fail = 0;

function check(string $label, mixed $got, mixed $expected): void {
    global $pass, $fail;
    $ok = ($got === $expected);
    if ($ok) {
        echo "  OK   $label\n";
        $pass++;
    } else {
        echo "  FAIL $label\n";
        echo "       got      = " . var_export($got, true) . "\n";
        echo "       expected = " . var_export($expected, true) . "\n";
        $fail++;
    }
}

function section(string $title): void {
    echo "\n============================================================\n";
    echo "  $title\n";
    echo "============================================================\n";
}

$wssFile = __DIR__ . '/wss_value.phopol';

// ===================================================================
// 1. Parser inspection — check PHoPolField descriptors
// ===================================================================
section('1. PHoPolParser — initialValue / initialIsFill descriptors');

$parser  = new PHoPolParser();
$layouts = $parser->parse(file_get_contents($wssFile));

$scLayout = $layouts['WsScalarVal'] ?? null;
check('WsScalarVal layout exists', $scLayout !== null, true);

if ($scLayout) {
    $f = $scLayout->allFields();

    // string literal → stored as plain string 'HELLO'
    check('alphaHello.initialValue',  $f['alphaHello']->initialValue,  'HELLO');
    check('alphaHello.initialIsFill', $f['alphaHello']->initialIsFill, false);

    // numeric literal 42 — PHP 8 . < + precedence gives string '42'
    check('numCount.initialValue is_numeric', is_numeric((string)$f['numCount']->initialValue), true);
    check('numCount.initialValue (int cast)', (int)$f['numCount']->initialValue, 42);
    check('numCount.initialIsFill', $f['numCount']->initialIsFill, false);

    // negative literal -75 → sign token prepended, string '-75'
    check('negAmount.initialValue (int cast)', (int)$f['negAmount']->initialValue, -75);
    check('negAmount.initialIsFill', $f['negAmount']->initialIsFill, false);

    // ZERO → integer 0 (from match arm, not default)
    check('numZero.initialValue',  $f['numZero']->initialValue,  0);
    check('numZero.initialIsFill', $f['numZero']->initialIsFill, false);

    // SPACES → fill byte ' ', fill=true
    check('fillSpaces.initialValue',  $f['fillSpaces']->initialValue,  ' ');
    check('fillSpaces.initialIsFill', $f['fillSpaces']->initialIsFill, true);

    // HIGH_VALUES → fill byte 0xFF, fill=true
    check('fillHigh.initialValue',  $f['fillHigh']->initialValue,  "\xFF");
    check('fillHigh.initialIsFill', $f['fillHigh']->initialIsFill, true);

    // LOW_VALUES → fill byte 0x00, fill=true
    check('fillLow.initialValue',  $f['fillLow']->initialValue,  "\x00");
    check('fillLow.initialIsFill', $f['fillLow']->initialIsFill, true);

    // QUOTE → '"', fill=false
    check('quoteField.initialValue',  $f['quoteField']->initialValue,  '"');
    check('quoteField.initialIsFill', $f['quoteField']->initialIsFill, false);
}

// OCCURS sub-field: initialValue/initialIsFill must survive parseGroupBody wrapping
$ovLayout = $layouts['WsOccurVal'] ?? null;
check('WsOccurVal layout exists', $ovLayout !== null, true);

if ($ovLayout) {
    $f  = $ovLayout->allFields();
    $sf = $f['slot[*]_code'] ?? null;
    check('slot[*]_code field exists', $sf !== null, true);
    if ($sf) {
        check('slot[*]_code.initialValue',  $sf->initialValue,  "\xFF");
        check('slot[*]_code.initialIsFill', $sf->initialIsFill, true);
        check('slot[*]_code.occursMax',     $sf->occursMax,     4);
        check('slot[*]_code.entrySize',     $sf->entrySize,     3);
    }
}

// WsNoVal — no VALUE clauses, all initialValue must be null
$nvLayout = $layouts['WsNoVal'] ?? null;
check('WsNoVal layout exists', $nvLayout !== null, true);
if ($nvLayout) {
    $noValue = array_filter(
        $nvLayout->allFields(),
        fn($f) => $f->initialValue !== null
    );
    check('WsNoVal: no field has initialValue', count($noValue), 0);
}

// ===================================================================
// 2. bootstrap() — buffers initialised by VALUE on construction
// ===================================================================
section('2. bootstrap() — VALUE applied on level construction');

$wss = bootstrap($wssFile);
$sc  = $wss->getLevel('WsScalarVal');
$ov  = $wss->getLevel('WsOccurVal');
$nv  = $wss->getLevel('WsNoVal');

check('alphaHello = HELLO (space-padded)', $sc->get('alphaHello'), 'HELLO     ');
check('numCount = 42',                     $sc->get('numCount'),   42);
check('negAmount = -75',                   $sc->get('negAmount'),  -75);
check('numZero = 0',                       $sc->get('numZero'),    0);
check('fillSpaces = 10 spaces',            $sc->get('fillSpaces'), '          ');
check('fillHigh = 3× 0xFF',               $sc->get('fillHigh'),   "\xFF\xFF\xFF");
check('fillLow = 3× 0x00',                $sc->get('fillLow'),    "\x00\x00\x00");
check('quoteField = "  ',                  $sc->get('quoteField'), '"  ');

// OCCURS cells — each of 4 cells must be HIGH_VALUES
for ($i = 1; $i <= 4; $i++) {
    check("slot[$i] code = HIGH_VALUES", $ov->cell('slot', $i)->get('code'), "\xFF\xFF\xFF");
}

// No-VALUE level — space fill default
check('WsNoVal alpha → spaces', $nv->get('alpha'), '        ');
check('WsNoVal num → 0',        $nv->get('num'),   0);

// ===================================================================
// 3. attach() overwrites VALUE bytes — VALUE NOT re-applied
// ===================================================================
section('3. attach() overwrites VALUE (no re-apply)');

// WsScalarVal total = 10+5+5+5+10+3+3+3 = 44 bytes
$sc->attach(str_repeat("\x20", 44));
check('after attach: alphaHello = spaces (NOT HELLO)',  $sc->get('alphaHello'), '          ');
check('after attach: fillHigh = spaces (NOT 0xFF×3)',   $sc->get('fillHigh'),   '   ');
check('after attach: numCount = 0 (spaces decoded)',    $sc->get('numCount'),   0);

// ===================================================================
// 4. initialize() resets to type defaults — VALUE NOT restored
// ===================================================================
section('4. initialize() does NOT restore VALUE');

$wss2 = bootstrap($wssFile);
$sc2  = $wss2->getLevel('WsScalarVal');

check('before initialize: alphaHello = HELLO', $sc2->get('alphaHello'), 'HELLO     ');
check('before initialize: numCount = 42',       $sc2->get('numCount'),   42);

initialize($sc2);

check('after initialize: alphaHello = spaces (NOT HELLO)', $sc2->get('alphaHello'), '          ');
check('after initialize: numCount = 0 (NOT 42)',            $sc2->get('numCount'),   0);
check('after initialize: negAmount = 0 (NOT -75)',          $sc2->get('negAmount'),  0);
check('after initialize: fillHigh = spaces (NOT 0xFF)',     $sc2->get('fillHigh'),   '   ');

// ===================================================================
echo "\n------------------------------------------------------------\n";
echo "  $pass passed, $fail failed\n";
echo "============================================================\n";
