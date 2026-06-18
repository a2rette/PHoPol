<?php
// php.exe -d extension=php_phopol.dll -d error_reporting=E_ALL -d display_errors=1 test_runtime.php
//
// Tests all global runtime functions and figurative constants added in phopol_runtime.c:
//   edit_format, inspect_tally, inspect_tally_leading, inspect_replace, inspect_convert,
//   initialize (method + global), move_corresponding, add_corresponding, search_all,
//   all figurative constant forms (singular + plural)

$pass = 0;
$fail = 0;

function check(string $label, mixed $got, mixed $expected, float $eps = 0.0001): void {
    global $pass, $fail;
    $ok = is_float($expected)
        ? abs((float)$got - $expected) < $eps
        : $got === $expected;
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
// 1. FIGURATIVE CONSTANTS
// ===================================================================
echo "============================================================\n";
echo "  1. Figurative constants\n";
echo "============================================================\n";

// Singular forms
check('SPACE',       SPACE,       ' ');
check('ZERO',        ZERO,        0);
check('HIGH_VALUE',  HIGH_VALUE,  "\xFF");
check('LOW_VALUE',   LOW_VALUE,   "\x00");
check('QUOTE',       QUOTE,       '"');
// Plural / alternate forms
check('SPACES',      SPACES,      ' ');
check('ZEROS',       ZEROS,       0);
check('ZEROES',      ZEROES,      0);
check('HIGH_VALUES', HIGH_VALUES, "\xFF");
check('LOW_VALUES',  LOW_VALUES,  "\x00");
check('QUOTES',      QUOTES,      '"');

// ===================================================================
// 2. EDIT_FORMAT
// ===================================================================
echo "\n============================================================\n";
echo "  2. edit_format\n";
echo "============================================================\n";

// 9 — mandatory digit: always shows, pads with leading zeros
check('9999 / 42',          edit_format('9999', 42),     '0042');
check('9999 / 0',           edit_format('9999', 0),      '0000');

// Z — suppress leading zeros with space; last mandatory position always shows
check('ZZZ9 / 42',          edit_format('ZZZ9', 42),     '  42');
check('ZZZ9 / 0',           edit_format('ZZZ9', 0),      '   0');
check('ZZZZ / 0',           edit_format('ZZZZ', 0),      '    ');
check('ZZZZ / 1234',        edit_format('ZZZZ', 1234),   '1234');

// Thousands separator + decimal point
check('ZZZ,ZZ9.99 / 1234.56',
    edit_format('ZZZ,ZZ9.99', 1234.56), '  1,234.56');
check('ZZZ,ZZ9.99 / 0',
    edit_format('ZZZ,ZZ9.99', 0),       '      0.00');
check('ZZZ,ZZ9.99 / 12.30',
    edit_format('ZZZ,ZZ9.99', 12.30),   '     12.30');

// * — asterisk fill (check protection); last position shows the digit
check('**** / 42',          edit_format('****', 42),     '**42');
check('**** / 0',           edit_format('****', 0),      '****');

// + sign: always output; - sign: space for positive, - for negative
check('ZZZ9+ / 42',         edit_format('ZZZ9+', 42),    '  42+');
check('ZZZ9+ / -42',        edit_format('ZZZ9+', -42),   '  42-');
check('ZZZ9- / 42',         edit_format('ZZZ9-', 42),    '  42 ');
check('ZZZ9- / -42',        edit_format('ZZZ9-', -42),   '  42-');

// DECIMAL POINT IS COMMA: ',' is decimal separator, '.' is thousands inserter
check('dpc / 1234.56',
    edit_format('ZZZ.ZZ9,99', 1234.56, true), '  1.234,56');
check('dpc / 0',
    edit_format('ZZZ.ZZ9,99', 0,       true), '      0,00');

// ===================================================================
// 3. INSPECT FUNCTIONS
// ===================================================================
echo "\n============================================================\n";
echo "  3. inspect_tally / inspect_tally_leading / inspect_replace / inspect_convert\n";
echo "============================================================\n";

// inspect_tally — count all occurrences
check('tally ABCABCABC / BC',  inspect_tally('ABCABCABC', 'BC'),  3);
check('tally AAABBB / A',      inspect_tally('AAABBB', 'A'),       3);
check('tally no match',        inspect_tally('HELLO', 'X'),         0);
check('tally empty target',    inspect_tally('HELLO', ''),          0);
check('tally AAAA / AA',       inspect_tally('AAAA', 'AA'),         2); // non-overlapping

// inspect_tally_leading — count only leading occurrences
check('leading AAABBB / A',    inspect_tally_leading('AAABBB', 'A'),    3);
check('leading AABABAB / A',   inspect_tally_leading('AABABAB', 'A'),   2);
check('leading no leading',    inspect_tally_leading('BAAB', 'A'),      0);
check('leading empty target',  inspect_tally_leading('HELLO', ''),      0);
check('leading 000123 / 0',    inspect_tally_leading('000123', '0'),    3);

// inspect_replace — replace all occurrences
check('replace O→0',           inspect_replace('HELLO WORLD', 'O', '0'),   'HELL0 W0RLD');
check('replace AA→B',          inspect_replace('AAABAA', 'AA', 'B'),        'BABB');  // A(0-1)→B, A(2), B(3), A(4-5)→B
check('replace no match',      inspect_replace('HELLO', 'X', 'Y'),          'HELLO');
check('replace to longer',     inspect_replace('abc', 'b', 'XYZ'),          'aXYZc');
check('replace to shorter',    inspect_replace('aXXb', 'XX', 'Y'),          'aYb');

// inspect_convert — character-by-character translation (strtr-style)
check('convert lower→upper',
    inspect_convert('hello', 'abcdefghijklmnopqrstuvwxyz',
                             'ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 'HELLO');
check('convert aeiou→AEIOU',
    inspect_convert('hello world', 'aeiou', 'AEIOU'),       'hEllO wOrld');
check('convert no match',
    inspect_convert('HELLO', 'abc', 'ABC'),                  'HELLO');
check('convert digits',
    inspect_convert('a1b2c3', '123', 'XYZ'),                 'aXbYcZ');

// ===================================================================
// 4. INITIALIZE (method on PHoPolLevel01 + global variadic function)
// ===================================================================
echo "\n============================================================\n";
echo "  4. initialize\n";
echo "============================================================\n";

// Layout: alpha (DISPLAY,digits=0), numeric display (digits=3),
//         binary signed int32, packed decimal (2 bytes, no decimal places)
phopol_register_layout('WsInitTest', 14, [
    ['name'=>'alphaField',  'offset'=> 0, 'length'=>5, 'type'=>PHOPOL_TYPE_DISPLAY,
     'digits'=>0, 'decimals'=>0, 'flags'=>0],
    ['name'=>'numField',    'offset'=> 5, 'length'=>3, 'type'=>PHOPOL_TYPE_DISPLAY,
     'digits'=>3, 'decimals'=>0, 'flags'=>0],
    ['name'=>'binField',    'offset'=> 8, 'length'=>4, 'type'=>PHOPOL_TYPE_BINARY,
     'digits'=>0, 'decimals'=>0, 'flags'=>PHOPOL_FLAG_SIGNED],
    ['name'=>'packedField', 'offset'=>12, 'length'=>2, 'type'=>PHOPOL_TYPE_PACKED,
     'digits'=>0, 'decimals'=>0, 'flags'=>0],
]);

$level = new PHoPolLevel01('WsInitTest');
$level->allocate();

// Set values before initialize
$level->alphaField  = 'HELLO';
$level->numField    = 42;
$level->binField    = 999;
$level->packedField = -77;

check('pre-init alphaField',   $level->alphaField,  'HELLO');
check('pre-init numField',     $level->numField,     42);
check('pre-init binField',     $level->binField,     999);
check('pre-init packedField',  $level->packedField, -77);

// Method: $level->initialize() — reset to type defaults
$level->initialize();

check('post-init alphaField',  $level->alphaField,  '     ');  // spaces
check('post-init numField',    $level->numField,     0);        // '000' → 0
check('post-init binField',    $level->binField,     0);        // zero bytes
check('post-init packedField', $level->packedField,  0);        // BCD +0 (0x00 0x0C)

// Global function initialize($a, $b, ...) — variadic, resets multiple levels
$a = new PHoPolLevel01('WsInitTest');
$a->allocate();
$b = new PHoPolLevel01('WsInitTest');
$b->allocate();

$a->numField = 100;   $a->binField = 200;
$b->numField = 300;   $b->binField = 400;

initialize($a, $b);

check('global init: a->numField', $a->numField,  0);
check('global init: a->binField', $a->binField,  0);
check('global init: b->numField', $b->numField,  0);
check('global init: b->binField', $b->binField,  0);

// ===================================================================
// 5. MOVE CORRESPONDING
// ===================================================================
echo "\n============================================================\n";
echo "  5. move_corresponding\n";
echo "============================================================\n";

// WsMcSrc and WsMcDst share mcName/mcAmount/mcCount; only Dst has mcExtra
phopol_register_layout('WsMcSrc', 18, [
    ['name'=>'mcName',   'offset'=> 0, 'length'=>10, 'type'=>PHOPOL_TYPE_DISPLAY, 'digits'=>0, 'decimals'=>0, 'flags'=>0],
    ['name'=>'mcAmount', 'offset'=>10, 'length'=> 5, 'type'=>PHOPOL_TYPE_DISPLAY, 'digits'=>5, 'decimals'=>0, 'flags'=>0],
    ['name'=>'mcCount',  'offset'=>15, 'length'=> 3, 'type'=>PHOPOL_TYPE_DISPLAY, 'digits'=>3, 'decimals'=>0, 'flags'=>0],
]);
phopol_register_layout('WsMcDst', 23, [
    ['name'=>'mcName',   'offset'=> 0, 'length'=>10, 'type'=>PHOPOL_TYPE_DISPLAY, 'digits'=>0, 'decimals'=>0, 'flags'=>0],
    ['name'=>'mcAmount', 'offset'=>10, 'length'=> 5, 'type'=>PHOPOL_TYPE_DISPLAY, 'digits'=>5, 'decimals'=>0, 'flags'=>0],
    ['name'=>'mcCount',  'offset'=>15, 'length'=> 3, 'type'=>PHOPOL_TYPE_DISPLAY, 'digits'=>3, 'decimals'=>0, 'flags'=>0],
    ['name'=>'mcExtra',  'offset'=>18, 'length'=> 5, 'type'=>PHOPOL_TYPE_DISPLAY, 'digits'=>0, 'decimals'=>0, 'flags'=>0],
]);

$src = new PHoPolLevel01('WsMcSrc');  $src->allocate();
$dst = new PHoPolLevel01('WsMcDst');  $dst->allocate();

$src->mcName   = 'ALICE';
$src->mcAmount = 12345;
$src->mcCount  = 7;
$dst->mcExtra  = 'XTRA';

move_corresponding($src, $dst);

check('mc: mcName moved',         rtrim($dst->mcName),   'ALICE');
check('mc: mcAmount moved',       $dst->mcAmount,         12345);
check('mc: mcCount moved',        $dst->mcCount,          7);
check('mc: mcExtra untouched',    rtrim($dst->mcExtra),  'XTRA');   // not in src

// Overwrite src, move again — dst should reflect new src values
$src->mcName   = 'BOB';
$src->mcAmount = 99;
move_corresponding($src, $dst);
check('mc: 2nd move mcName',      rtrim($dst->mcName),   'BOB');
check('mc: 2nd move mcExtra',     rtrim($dst->mcExtra),  'XTRA');   // still untouched

// ===================================================================
// 6. ADD CORRESPONDING
// ===================================================================
echo "\n============================================================\n";
echo "  6. add_corresponding\n";
echo "============================================================\n";

$src2 = new PHoPolLevel01('WsMcSrc');  $src2->allocate();
$dst2 = new PHoPolLevel01('WsMcDst');  $dst2->allocate();

$src2->mcName   = 'SRC';
$src2->mcAmount = 1000;
$src2->mcCount  = 5;
$dst2->mcName   = 'DST';
$dst2->mcAmount = 2000;
$dst2->mcCount  = 3;
$dst2->mcExtra  = 'BASE';

add_corresponding($src2, $dst2);

check('ac: mcAmount added',          $dst2->mcAmount,           3000);  // 2000 + 1000
check('ac: mcCount added',           $dst2->mcCount,             8);    // 3 + 5
check('ac: mcName unchanged (alpha)', rtrim($dst2->mcName),     'DST'); // alpha skipped
check('ac: mcExtra unchanged',        rtrim($dst2->mcExtra),   'BASE'); // not in src

// Add again: cumulative
add_corresponding($src2, $dst2);
check('ac: cumulative mcAmount',     $dst2->mcAmount,           4000);  // 3000 + 1000
check('ac: cumulative mcCount',      $dst2->mcCount,            13);    // 8 + 5

// ===================================================================
// 7. SEARCH ALL
// ===================================================================
echo "\n============================================================\n";
echo "  7. search_all (binary search on OCCURS table)\n";
echo "============================================================\n";

// Single-field OCCURS: 6 cells of a 3-digit numeric code
phopol_register_layout('WsLookup', 18, [
    ['name'=>'tableEntry[*]_code', 'offset'=>0, 'length'=>3,
     'type'=>PHOPOL_TYPE_DISPLAY, 'digits'=>3, 'decimals'=>0, 'flags'=>0,
     'occursMax'=>6, 'entrySize'=>3],
]);

$lookup = new PHoPolLevel01('WsLookup');
$lookup->allocate();

// Pre-fill in ascending order (required by SEARCH ALL)
for ($i = 1; $i <= 6; $i++) {
    $lookup->tableEntry[$i]->code = $i * 100;
}

check('search middle (300)',     search_all($lookup, 'tableEntry', 'code', 300),  3);
check('search first  (100)',     search_all($lookup, 'tableEntry', 'code', 100),  1);
check('search last   (600)',     search_all($lookup, 'tableEntry', 'code', 600),  6);
check('search second (200)',     search_all($lookup, 'tableEntry', 'code', 200),  2);
check('search not found (low)',  search_all($lookup, 'tableEntry', 'code', 50),   null);
check('search not found (mid)',  search_all($lookup, 'tableEntry', 'code', 250),  null);
check('search not found (high)', search_all($lookup, 'tableEntry', 'code', 999),  null);

// -------------------------------------------------------------------
echo "\n------------------------------------------------------------\n";
echo "  $pass passed, $fail failed\n";
echo "============================================================\n";
