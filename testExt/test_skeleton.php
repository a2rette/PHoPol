<?php
// php.exe -d extension=php_phopol.dll -d error_reporting=E_ALL -d display_errors=1 test_skeleton.php

// --- 1. Register the layout (what the PHP parser will do at bootstrap) ---
phopol_register_layout('WsIdentification', 14, [
    ['name' => 'programName', 'offset' =>  0, 'length' => 10,
     'type' => PHOPOL_TYPE_DISPLAY, 'digits' => 0, 'decimals' => 0, 'flags' => 0],
    ['name' => 'version',     'offset' => 10, 'length' =>  2,
     'type' => PHOPOL_TYPE_DISPLAY, 'digits' => 2, 'decimals' => 0, 'flags' => 0],
    ['name' => 'revision',    'offset' => 12, 'length' =>  2,
     'type' => PHOPOL_TYPE_DISPLAY, 'digits' => 2, 'decimals' => 0, 'flags' => 0],
]);

// --- 2. Instantiate and allocate ---
$rec = new PHoPolLevel01('WsIdentification');
$rec->allocate();
echo "After allocate, rawBytes: [" . $rec->rawBytes() . "]\n";

// --- 3. String field ---
$rec->programName = 'SHOWCASE';
echo "write 'SHOWCASE'   → [" . $rec->programName . "]\n";

$rec->programName = 'HI';
echo "write 'HI'         → [" . $rec->programName . "]\n";

$rec->programName = 'TOOLONGSTRING!';
echo "write 'TOOLONG...' → [" . $rec->programName . "]\n";

// --- 4. Numeric fields ---
$rec->version  = 3;
$rec->revision = 14;
echo "version=3   → " . $rec->version  . "\n";
echo "revision=14 → " . $rec->revision . "\n";

// --- 5. attach() ---
$rec->attach('COBOL     0102');
echo "\nAfter attach('COBOL     0102'):\n";
echo "  programName = [" . $rec->programName . "]\n";
echo "  version     = "  . $rec->version     . "\n";
echo "  revision    = "  . $rec->revision    . "\n";

// --- 6. Register called twice: second call is a no-op ---
phopol_register_layout('WsIdentification', 14, []);
echo "\nDouble-register: no error\n";

// --- 7. Unknown layout ---
echo "Unknown layout: ";
try { $bad = new PHoPolLevel01('NoSuchLayout'); }
catch (Error $e) { echo "Error: " . $e->getMessage() . "\n"; }

// --- 8. Unknown field ---
echo "Unknown field:  ";
try { $x = $rec->noSuchField; }
catch (Error $e) { echo "Error: " . $e->getMessage() . "\n"; }
