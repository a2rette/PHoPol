<?php

// ============================================================
// PHP/COBOL — Working Storage declarations (chunk 1)
// Elementary items and group structures
// ============================================================

// --- LEVEL 01 group (becomes a class-like structure) ---
// In standard PHP we'd use a class, but our COBOL-PHP uses
// a dedicated  'record'  keyword — no methods, pure data.

record WsIdentification {

    // PIC X(10) VALUE 'SHOWCASE'
    // X = string, length enforced, right-padded with spaces
    string<10> $programName = 'SHOWCASE';

    // PIC 9(2) VALUE 1  →  unsigned int, max 2 digits
    uint<2> $version = 1;

    // PIC 9(2) VALUE 0
    uint<2> $revision = 0;
}


// --- Alphanumeric (PIC X) ---
record WsAlphaSamples {

    string<1>  $char1;                        // PIC X
    string<10> $char10;                       // PIC X(10)
    string<20> $charSpaces  = SPACES;         // VALUE SPACES  (figurative constant)
    string<5>  $charLiteral = 'HELLO';        // VALUE 'HELLO'
}


// --- Alphabetic (PIC A) — only A-Z + space ---
// New type keyword  'alpha'  to distinguish from  'string' (PIC X)
record WsAlphaOnly {

    alpha<15> $lettersOnly;                   // PIC A(15)
}


// --- Signed / unsigned integers, implied decimal ---
record WsNumericDisplay {

    uint<5>          $int5;                   // PIC 9(5)
    int<5>           $intSigned;              // PIC S9(5)

    // SIGN LEADING SEPARATE → stored with an explicit +/- byte
    int<5> signed_separate $intSignedSep;     // PIC S9(5) SIGN LEADING SEPARATE

    // V = implied decimal: 5 integer digits, 2 decimal digits
    // 'decimal' is our type for PIC 9(n)V9(m) — no actual decimal point stored
    decimal<5,2>     $amount;                 // PIC 9(5)V9(2)
    sdecimal<5,2>    $amountSigned;           // PIC S9(5)V9(2)  (s-prefix = signed)
}


// --- EDITED PICTURES ---
// Edited items are display-only (no arithmetic).
// We keep the COBOL mask syntax inside  edited<"mask">
// since inventing a new notation would just be confusing.

record WsEditedSamples {

    edited<"ZZZ,ZZ9.99">       $amtEdited;      // suppress leading zeros
    edited<"$$$,$$$,$$9.99">   $amtDollar;      // floating $
    edited<"***,**9.99">       $amtAsterisk;    // cheque protection
    edited<"+ZZZ,ZZ9.99">      $amtSignedEd;    // explicit sign
    edited<"ZZZ,ZZ9.99CR">     $amtCrDr;        // CR/DR suffix
    edited<"99/99/9999">       $dateEdited;     // slash insertion
    edited<"(999)B999-9999">   $phoneEdited;    // B = blank insertion
}


// --- USAGE BINARY / COMP ---
// 'binary' keyword replaces USAGE BINARY / USAGE COMP.
// Size in bytes is inferred from digit count, just like COBOL:
//   int<1..4>  binary  →  2 bytes
//   int<5..9>  binary  →  4 bytes
//   int<10..18> binary →  8 bytes

record WsBinaryItems {

    int<4>  binary $binShort;        // PIC S9(4)  USAGE BINARY  (2 bytes)
    int<9>  binary $binInt;          // PIC S9(9)  USAGE BINARY  (4 bytes)
    int<18> binary $binLong;         // PIC S9(18) USAGE BINARY  (8 bytes)
    uint<9> binary $binUnsigned;     // PIC 9(9)   USAGE BINARY  (no sign)

    // COMP is a straight synonym — same output, both accepted
    int<9>  comp   $compInt;         // PIC S9(9)  USAGE COMP
}


// --- COMP-1 / COMP-2  (floating point) ---
// No PIC clause in COBOL → no type parameter here either.
// 'float32' and 'float64' are self-documenting aliases.

record WsFloatItems {

    float32 $floatSingle;            // USAGE COMP-1  (4 bytes, ~7 sig. digits)
    float64 $floatDouble;            // USAGE COMP-2  (8 bytes, ~15 sig. digits)
}


// --- COMP-3  (PACKED-DECIMAL) ---
// 'packed' modifier on decimal/sdecimal.
// Storage: ceil((digits+1)/2) bytes — compiler works it out.

record WsPackedItems {

    int<5>       packed $packed5;      // PIC S9(5)       COMP-3  (3 bytes)
    int<9>       packed $packed9;      // PIC S9(9)       COMP-3  (5 bytes)
    sdecimal<7,2> packed $packedDec;   // PIC S9(7)V9(2)  COMP-3  (5 bytes)
}


// --- COMP-5  (NATIVE BINARY — exact machine integer) ---
// 'native' modifier: value range = full machine int, not capped by digit count.
// Used for C interop / syscalls.

record WsComp5Items {

    int<4>  native $c5Word;           // PIC S9(4)  COMP-5  (16-bit)
    int<9>  native $c5Dword;          // PIC S9(9)  COMP-5  (32-bit)
    int<18> native $c5Qword;          // PIC S9(18) COMP-5  (64-bit)
}


// --- POINTER / PROCEDURE-POINTER ---

record WsPointerItems {

    pointer          $ptr;            // USAGE POINTER
    procedure_pointer $procPtr;       // USAGE PROCEDURE-POINTER
}


// --- FIGURATIVE CONSTANTS and VALUE initialisers ---
// Recognised as bare keywords (no quotes), just like COBOL.

record WsInitialValues {

    uint<5>        $zeroNum     = ZERO;         // VALUE ZERO
    uint<5>        $zeroNum2    = ZEROS;         // VALUE ZEROS  (synonym)
    string<10>     $blankAlpha  = SPACES;        // VALUE SPACES
    string<1>      $highByte    = HIGH_VALUES;   // VALUE HIGH-VALUES
    string<1>      $lowByte     = LOW_VALUES;    // VALUE LOW-VALUES
    string<1>      $quoteChar   = QUOTE;         // VALUE QUOTE

    // Numeric literal with packed storage
    sdecimal<1,8>  packed $pi   = 3.14159265;   // PIC S9V9(8) COMP-3
}


// --- LEVEL 77  (standalone elementary) ---
// Outside any record block; 'standalone' keyword signals it.
// Old style — valid but discouraged in new code.

standalone int<5>    packed $wsStandaloneCtr  = ZERO;
standalone string<1>        $wsStandaloneFlag = 'N';
standalone uint<9>  binary  $wsStandaloneMax  = 999999999;


// --- REDEFINES ---
// In COBOL, REDEFINES overlays the same memory with a different
// structure. In PHP/COBOL we use a  'redefines'  keyword after
// the record name, pointing to the original record.
// The compiler guarantees both share the same storage.
// Redefining record must not exceed the size of the original.

// Original: 8-byte date as a plain number  YYYYMMDD
record WsDateNumeric {
    uint<8> $date;                            // PIC 9(8)
}

// Structural overlay — splits the 8 bytes into three fields
record WsDateParts redefines WsDateNumeric {
    uint<4> $yyyy;                            // PIC 9(4)
    uint<2> $mm;                              // PIC 9(2)
    uint<2> $dd;                              // PIC 9(2)
}


// Another classic: same 4 bytes seen as a string OR a byte array.
// The byte-array view uses an inline  redefines  annotation
// directly on the field when the overlay is simple enough
// to not need a full separate record.

record WsWordAlpha {
    string<4> $word;                          // PIC X(4)

    // inline redefines: array<string<1>, 4> overlays $word exactly
    redefines($word) array<string<1>, 4> $bytes;  // PIC X OCCURS 4 TIMES
}


// Numeric redefine: packed decimal view over raw bytes
record WsRawBytes {
    string<5>              $rawBytes;         // PIC X(5)

    redefines($rawBytes)
    sdecimal<9,0> packed   $packedView;       // PIC S9(9) COMP-3
}


// --- CONDITION NAMES  (LEVEL 88) ---
// In COBOL, 88s are attached to a parent field and define
// named boolean conditions.  IF WS-STATUS-OK is cleaner than
// IF WS-STATUS = 'OK'.
//
// In PHP/COBOL we attach them inside the record as  'when'
// clauses bound to a specific field.  They produce a virtual
// read-only bool property on the record instance.
//
// Syntax:
//   when $field == <value>          : bool $conditionName;
//   when $field == <v1> | <v2>      : bool $conditionName;  // multi-value
//   when $field in <v1>..<v2>       : bool $conditionName;  // range (THRU)

record WsStatusCode {

    string<2> $statusCode;                    // PIC X(2)

    when $statusCode == 'OK'         : bool $isOk;       // 88 WS-STATUS-OK
    when $statusCode == 'WN'         : bool $isWarn;     // 88 WS-STATUS-WARN
    when $statusCode == 'ER' | 'EX'  : bool $isError;   // 88 multi-value
    when $statusCode == 'FT'         : bool $isFatal;   // 88 WS-STATUS-FATAL
}

record WsReturnCode {

    int<4> binary $returnCode;                // PIC S9(4) COMP

    when $returnCode == 0            : bool $isSuccess;  // VALUE 0
    when $returnCode in 1..4         : bool $isWarning;  // VALUE 1 THRU 4
    when $returnCode in 8..99        : bool $isError;    // VALUE 8 THRU 99
    when $returnCode in 100..999     : bool $isAbend;    // VALUE 100 THRU 999
}

record WsSwitch {

    string<1> $switch;                        // PIC X

    // 88 with multiple values across two types (Y/y/1 all mean ON)
    when $switch == 'Y' | 'y' | '1' : bool $isOn;       // VALUE 'Y' 'y' '1'
    when $switch == 'N' | 'n' | '0' : bool $isOff;      // VALUE 'N' 'n' '0'
}


// --- RENAMES  (LEVEL 66) ---
// COBOL 66 creates an alias spanning a contiguous range of fields,
// without allocating new storage.
//
// In PHP/COBOL:  'alias'  keyword (clearer than 'renames'),
// pointing at a field range with  field1..field2  notation.
// Like 66, it cannot span items with OCCURS, and cannot alias
// another alias or a condition name.

record WsRecordFull {

    string<2>      $recHeader;                // PIC X(2)
    string<8>      $recKey;                   // PIC X(8)
    sdecimal<9,2>  packed $recAmount;         // PIC S9(9)V99 COMP-3
    uint<8>        $recDate;                  // PIC 9(8)
    string<10>     $recFiller;               // PIC X(10)

    // Alias spanning $recKey through $recAmount  (66 ... RENAMES ... THRU)
    alias $recKey..$recAmount  : $recKeyAndAmount;

    // Alias spanning $recAmount through $recFiller
    alias $recAmount..$recFiller : $recEverythingElse;
}


// --- FILLER ---
// Anonymous padding field — cannot be referenced by name.
// In PHP/COBOL we keep the  'filler'  keyword (lowercase),
// with the same type syntax as any other field.
// No variable name — that's the whole point.

record WsPaddedRecord {

    string<2>     $prType;                   // PIC X(2)
    filler string<3>;                        // PIC X(3)  — 3 bytes padding
    sdecimal<9,2> packed $prAmount;          // PIC S9(9)V99 COMP-3
    filler string<10> = SPACES;             // PIC X(10) VALUE SPACES
}


// --- FIXED-LENGTH TABLE ---
// COBOL: OCCURS n TIMES INDEXED BY idx
//
// In PHP/COBOL:  array<type, size>  for the field type,
// plus an  indexed_by  clause for the COBOL-style index
// (1-based, opaque integer — distinct from a PHP array key).
// The index variable is declared inside  []  after the field name,
// scoped to the enclosing record.

record WsFixedTable {

    // Each entry is itself a sub-record (group item under OCCURS)
    // We inline the sub-structure with an anonymous  struct { }
    // rather than forcing a separate named record for every table.

    array<struct {
        string<9>      $monthName;            // PIC X(9)
        uint<2>        $monthDays;            // PIC 9(2)
        sdecimal<9,2>  packed $monthTotal;    // PIC S9(9)V99 COMP-3
    }, 12> $monthEntry [index $monthIdx];     // OCCURS 12 TIMES INDEXED BY WS-MONTH-IDX
}


// --- VARIABLE-LENGTH TABLE  (OCCURS DEPENDING ON) ---
// The actual number of active entries is held in a separate
// field ($itemCount here).  The table pre-allocates for the
// maximum (100) but only  $itemCount  entries are live.
//
// Syntax:  array<type, min..max> depends_on $field
// The compiler enforces:
//   - min >= 1
//   - $field must be an integer type in the same or enclosing record
//   - no REDEFINES may straddle an ODO table

record WsVariableTable {

    int<4> binary $itemCount = ZERO;          // PIC S9(4) COMP  (the ODO object)

    array<struct {
        string<5>      $itemCode;             // PIC X(5)
        sdecimal<7,2>  packed $itemValue;     // PIC S9(7)V99 COMP-3
    }, 1..100> $item [index $itemIdx]         // OCCURS 1 TO 100 TIMES
               depends_on $itemCount;         // DEPENDING ON WS-ITEM-COUNT
}


// --- SORTED TABLE  (ASCENDING/DESCENDING KEY → SEARCH ALL) ---
// COBOL allows SEARCH ALL (binary search) when the table declares
// its sort key with ASCENDING KEY or DESCENDING KEY.
//
// PHP/COBOL uses an  ordered_by  clause (asc/desc) to express this.
// The compiler then permits  search_all()  on the table.
// INDEXED BY is still declared with  [index ...]  as before.

record WsSortedTable {

    array<struct {
        string<10> $sortKey;                  // PIC X(10)  ← the search key
        string<40> $sortData;                 // PIC X(40)
    }, 500> $sortedEntry [index $sortIdx]     // OCCURS 500 TIMES INDEXED BY
            ordered_by asc($sortKey);         // ASCENDING KEY IS WS-SORT-KEY
}

// For a descending key:
//   ordered_by desc($sortKey)
//
// Multi-key example (COBOL allows a list of keys):
//   ordered_by asc($lastName), asc($firstName)


// --- MULTI-DIMENSIONAL TABLE  (nested OCCURS) ---
// Each OCCURS level gets its own  [index ...]  clause,
// and nesting is expressed by nesting  array<>  generics.
// Inner struct can itself be an array for deeper dimensions.

record WsMatrix {

    // 10×10 grid of packed decimals
    array
        array<struct {
            sdecimal<5,2> packed $cellVal;    // PIC S9(5)V99 COMP-3
        }, 10> [index $colIdx],               // inner OCCURS 10 TIMES
    10> $row [index $rowIdx];                 // outer OCCURS 10 TIMES
}


// --- OCCURS with VALUE initialisation ---
// COBOL does not allow VALUE on items under OCCURS (most compilers).
// PHP/COBOL relaxes this: a scalar default in the struct is applied
// to every cell at program initialisation — a quality-of-life addition.

record WsInitialisedTable {

    array<struct {
        string<1>     $flag    = 'N';         // every cell starts as 'N'
        sdecimal<7,2> packed $amount = ZERO;  // every cell starts as 0
    }, 50> $entry [index $entryIdx];
}


// --- OCCURS with REDEFINES  (overlay an entire table) ---
// Useful to view the same bytes as either a structured table
// or a flat string (e.g. for bulk I/O or hashing).

record WsTableRaw {
    string<40> $rawBlock;                     // PIC X(40)  — flat view

    // 8 entries × 5 bytes each = 40 bytes → exact overlay
    redefines($rawBlock)
    array<struct {
        string<3> $code;                      // PIC X(3)
        uint<2>   $count;                     // PIC 9(2)
    }, 8> $entries [index $entryIdx];
}

// --- JUSTIFIED RIGHT ---
// COBOL: PIC X(n) JUSTIFIED RIGHT
// Normally MOVE left-aligns alphanumeric; JUST RIGHT reverses it.
//
// PHP/COBOL: trailing  justify(right)  modifier on string fields.
// justify(left) is the implicit default and never needs spelling out.

record WsJustifiedItems {

    string<20> justify(right) $rightName;     // PIC X(20) JUSTIFIED RIGHT

    // left is the default — shown here only for documentation clarity
    string<20> justify(left)  $leftName;      // PIC X(20)  (normal)
}


// --- BLANK WHEN ZERO ---
// COBOL: edited picture clause; displays spaces instead of zeros
// when the value is zero.  Only meaningful on edited fields.
//
// PHP/COBOL: trailing  blank_zero  modifier, only valid on  edited<>  fields.
// Compiler raises an error if applied to any other type.

record WsBlankZeroItems {

    edited<"ZZ,ZZ9.99"> blank_zero $bzAmount; // PIC ZZ,ZZ9.99 BLANK WHEN ZERO
}


// --- SYNCHRONIZED (SYNC) ---
// COBOL: SYNCHRONIZED LEFT/RIGHT aligns a binary item to its
// natural memory boundary to avoid performance penalties on
// RISC / 64-bit platforms.  LEFT is the default on most compilers.
//
// PHP/COBOL:  sync  modifier, with optional  left  or  right  hint.
// Without a hint,  sync  alone implies  sync(left).

record WsSyncedItems {

    int<4>  binary sync       $syncShort;     // PIC S9(4)  BINARY SYNC
    int<9>  binary sync       $syncInt;       // PIC S9(9)  BINARY SYNC
    int<18> binary sync(left) $syncLong;      // explicit left alignment
    int<9>  binary sync(right) $syncRight;    // PIC S9(9)  BINARY SYNC RIGHT
}


// --- GLOBAL ---
// COBOL: item is visible to all programs nested (contained) within
// the declaring program.  Declared on the 01 level.
//
// PHP/COBOL:  global  keyword before  record  (or before  standalone).
// Visibility rule: the record is accessible to any sub-program
// compiled within the same compilation unit.

global record WsGlobalCounter {

    sdecimal<9,0> packed $ctr = ZERO;         // PIC S9(9) COMP-3 VALUE ZERO GLOBAL
}

// standalone global:
global standalone sdecimal<9,0> packed $wsGlobalCtr = ZERO;


// --- EXTERNAL ---
// COBOL: storage is shared across separately compiled programs
// that each declare the same name with EXTERNAL.
// Any program that declares it gets a reference to the same bytes.
//
// PHP/COBOL:  external  keyword before  record  (or  standalone).
// All translation units declaring the same external record name
// share one physical allocation — last-write-wins at runtime.
// Cannot be combined with  global  (different scoping mechanisms).

external record WsSharedStatus {

    string<2> $status = SPACES;              // PIC X(2) VALUE SPACES EXTERNAL
}

// standalone external:
external standalone string<2> $wsSharedStatus = SPACES;


// --- COMBINING MODIFIERS ---
// Modifiers can stack; order is:  storage  →  alignment  →  display
// (compiler enforces incompatible combinations, e.g. blank_zero on non-edited)

record WsCombined {

    // packed + sync: packed-decimal, boundary-aligned
    sdecimal<9,2> packed sync   $packedSynced;

    // binary + native + sync: full machine-width, aligned
    int<9> binary native sync   $nativeSynced;

    // edited + blank_zero + justify: right-justified, blank on zero
    // (contrived but syntactically valid)
    edited<"ZZZ,ZZ9.99"> blank_zero justify(right) $fancyAmount;
}
