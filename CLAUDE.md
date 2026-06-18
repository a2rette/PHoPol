# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this project is

PHoPol gives PHP COBOL-style WORKING-STORAGE SECTION semantics: flat memory buffers, field access by offset, REDEFINES overlays, OCCURS tables, and condition names (88-levels).

There are two implementations:

| | Phase 1 — FFI prototype | Phase 2 — C extension |
|---|---|---|
| Directory | `testFFI/` | `ext/` + `testExt/` |
| Status | Reference / legacy | Active development |
| Mechanism | PHP 8 FFI (`ffi.enable=true`) | Zend extension (`php_phopol.dll`) |
| Entry point | `testFFI/bootstrap.php` → `bootstrap()` | `ext/loadSection.php` → `PHoPol\loadSection()` |

Active work targets the C extension. `testFFI/` is kept as a reference; do not modify it.

## Repository layout

```
ext/                    Extension-side PHP support layer
  PHoPolParser.php        Tokenizer + parser → plain PHP arrays for phopol_register_layout()
  loadSection.php         PHoPol\loadSection() — parse + register + allocate entry point

testExt/                Tests for the C extension
  test_loadSection.php    End-to-end test (parse wss_simple.phopol → C extension → level R/W)
  test_value.php          VALUE clause initialisation tests
  test_skeleton.php       Basic skeleton / smoke tests
  test_packed.php         Packed-decimal encoding tests
  test_runtime.php        Runtime verb tests
  employee/               Migrated COBOL application demo (create + report)

testFFI/                Phase 1 FFI prototype (reference only — do not modify)
  PHoPolParser.php        Original parser → PHoPolLayout[] objects
  PHoPolField.php         Field descriptor (FieldType enum)
  PHoPolLayout.php        Level descriptor
  PHoPolLevel.php         Base class (FFI buffer view)
  PHoPolLevel01.php       Extends PHoPolLevel; anchors FFI buffer
  PHoPolRuntime.php       Built-in verbs + figurative constants
  bootstrap.php           WssContext + bootstrap()
  autoload.php            spl_autoload_register handler
  test_prototype.php      Main FFI test
  employee/               FFI employee demo

etude langage/          Grammar reference and language study files
  WORKING-STORAGE.phopol  Full .phopol grammar reference
```

`.phopol` data files (shared between both implementations) live in `testFFI/`.

## The `.phopol` language

Data sections are declared in a PHP-flavoured syntax:

```
namespace wss {
    level(01) $LevelName {
        string<10>     $field;                          // PIC X(10)
        uint<5>        $num;                            // PIC 9(5)
        int<9>  binary $counter;                        // PIC S9(9) COMP
        sdecimal<7,2>  packed $amount;                  // PIC S9(7)V99 COMP-3
        float64        $floatVal;                       // USAGE COMP-2
    }

    level(01) $DateParts redefines $DateNumeric { ... }  // REDEFINES

    level(01) $Table {
        level(05) $row occurs(12, index: $idx) { ... }  // OCCURS
    }

    standalone int<5> packed $counter;                  // level 77 equivalent
}
```

The full grammar reference is in `etude langage/WORKING-STORAGE.phopol`.

---

## C extension implementation

### Running tests

```
php -d extension=php_phopol.dll testExt/test_loadSection.php
php -d extension=php_phopol.dll testExt/test_value.php
php -d extension=php_phopol.dll testExt/employee/create_employees.php
php -d extension=php_phopol.dll testExt/employee/report_employees.php
```

No Composer, no external packages. `php_phopol.dll` must be available (on `extension_dir` or given as a full path).

### Runtime pipeline

```
.phopol file
    → PHoPolParser::parse()         tokenize + build plain PHP arrays
    → phopol_register_layout()      register each layout with the C extension
    → PHoPolLevel01::allocate()     allocate buffer + apply VALUE clauses
    → PHoPolLevel01::attachTo()     wire REDEFINES (shared buffer pointer)
    → array<string, PHoPolLevel01>  returned to caller by loadSection()
```

### `ext/PHoPolParser.php`

Outputs plain PHP arrays — no `PHoPolField`/`PHoPolLayout` objects. The array format matches `phopol_register_layout()` directly:

- **Type constants** (`PHOPOL_TYPE_DISPLAY=0` … `PHOPOL_TYPE_NATIVE=5`) and **flag constants** (`PHOPOL_FLAG_SIGNED=1`, `PHOPOL_FLAG_SIGN_SEPARATE=2`, `PHOPOL_FLAG_JUSTIFIED_RIGHT=4`, `PHOPOL_FLAG_BLANK_ZERO=8`) are defined by the C extension and used as-is.
- **Standalone fields**: each `standalone type $name` becomes a single-field layout (name = fieldName, totalLength = field.length).
- **Conditions**: each entry has either a `'values'` key or a `'range'` key — never both. `['name'=>..., 'field'=>..., 'values'=>[...]]` or `['name'=>..., 'field'=>..., 'range'=>[lo, hi]]`.
- **OCCURS sub-fields**: stored under key `groupName[*]_fieldName` in the fields array.

### `ext/loadSection.php` — `PHoPol\loadSection()`

```php
$levels = PHoPol\loadSection('/path/to/file.phopol');
// returns array<string, PHoPolLevel01>
```

Parses the file, calls `phopol_register_layout()` for every layout, allocates non-redefining levels, wires REDEFINES via `attachTo()`. Application code includes this file once.

### `PHoPolLevel01` (C extension class)

The C extension provides `PHoPolLevel01` as a native PHP class. Key methods:

| Method | Description |
|---|---|
| `allocate()` | Allocate buffer + apply VALUE clause initialisations |
| `attach(string $raw)` | Copy raw bytes into the buffer (one memcpy — equivalent of COBOL READ into WS) |
| `attachTo(PHoPolLevel01 $base)` | Share base's buffer — used for REDEFINES |
| `rawBytes(): string` | Return the raw buffer bytes |
| `__toString(): string` | Same as `rawBytes()` — enables `fwrite($fh, $level)` |

Field access via property syntax: `$level->fieldName = value` / `$value = $level->fieldName`.

OCCURS cell access: `$level->groupName[$idx]->subField` — returns a new `PHoPolLevel01` sub-view over the correct slice.

Condition names (88-levels): `$level->isActive` returns `bool`; `$level->isActive = true` writes the first VALUES value back to the parent field.

### REDEFINES

`attachTo($base)` links the overlay's buffer pointer directly to the base level's allocation. Any write through either level name modifies the same bytes. `loadSection()` handles this automatically for all `redefines` layouts in the file.

### OCCURS sub-field naming convention

The parser stores one template descriptor per sub-field under the key `groupName[*]_fieldName`. The C extension computes the cell buffer offset at access time: `cellOffset = groupBase + (idx - 1) * entrySize + subfieldDelta`. There is no separate cell class — cell access always returns a `PHoPolLevel01` sub-view.

### Decimal point is comma

When the `.phopol` source has a `decimal_point is comma` declaration in a `special_names` block, `decimalPointIsComma = true` is passed to `phopol_register_layout()`. The C extension then swaps the roles of `.` and `,` in edited picture masks, and OCCURS sub-views inherit the same locale.

### Type encoding

| `.phopol` type | COBOL equivalent | C type constant | Notes |
|---|---|---|---|
| `string<n>`, `alpha<n>` | PIC X(n)/A(n) | `PHOPOL_TYPE_DISPLAY` | ASCII, space-padded |
| `uint<n>`, `int<n>` | PIC 9(n)/S9(n) | `PHOPOL_TYPE_DISPLAY` | Zoned decimal; sign in last nibble |
| `int<n> signed_separate` | PIC S9(n) SIGN LEADING SEPARATE | `PHOPOL_TYPE_DISPLAY` | +/- as first byte |
| `decimal<n,m>`, `sdecimal<n,m>` | PIC 9(n)V9(m) | `PHOPOL_TYPE_DISPLAY` | Combined digits+decimals |
| `int<n> binary` / `comp` | USAGE BINARY | `PHOPOL_TYPE_BINARY` | `pack`/`unpack` s/l/q |
| `int<n> packed` | USAGE COMP-3 | `PHOPOL_TYPE_PACKED` | BCD nibbles, sign nibble at end |
| `int<n> native` | USAGE COMP-5 | `PHOPOL_TYPE_NATIVE` | Machine-native integer |
| `float32`, `float64` | USAGE COMP-1/2 | `PHOPOL_TYPE_FLOAT32/64` | `pack`/`unpack` f/d |

### Application code notes

- **No `declare(strict_types=1)`** in migrated COBOL programs: with strict_types, PHP 8 refuses to pass a `Stringable` object to `fwrite()`'s `string` parameter. Without it, `fwrite($fh, $WsEmployee)` transparently writes raw binary record bytes via `__toString()` — the semantic equivalent of COBOL's WRITE statement. `->rawBytes()` must not appear in application code.
- **OCCURS DEPENDING ON**: set the DEPENDS ON field before accessing cells. A value of 0 means range 1..0, which throws `\Error: OCCURS index out of range`.

---

## FFI prototype (`testFFI/` — reference only)

### Running

```
php testFFI/test_prototype.php
```

Requires PHP 8.1+ with `ffi.enable=true` in `php.ini`.

### Classes

| Class | Role |
|---|---|
| `PHoPolField` | Immutable field descriptor: offset, length, `FieldType` enum, `FieldFlags` bitmask, OCCURS metadata, condition names, `editMask` |
| `PHoPolLayout` | Immutable level descriptor: flat `name → PHoPolField` map, total byte length, optional `redefines` name, `decimalPointIsComma` flag |
| `PHoPolParser` | Tokenizes + parses `.phopol` source into `PHoPolLayout[]` |
| `PHoPolLevel` | Base class: typed view over an FFI buffer slice; `get()`/`set()`, `cell()`, `isCond()`/`setCond()`, `initialize()`, `dump()` |
| `PHoPolLevel01` | Extends `PHoPolLevel`; anchors the FFI `uint8_t[]` buffer; `attach()`, `attachBuffer()`, `registerRedefines()`, `rawBytes()` |
| `PHoPolRuntime` | Verb implementations: `moveCorresponding`, `addCorresponding`, `searchAll`, `inspectTally*`, `inspectReplace`, `inspectConvert`, `editFormat`; figurative constants; global aliases |
| `WssContext` | Container returned by `bootstrap()`; maps level names to `PHoPolLevel01` instances |

`autoload.php` registers a `spl_autoload_register` handler and explicitly `require_once`s `PHoPolRuntime.php`, `bootstrap.php`, and `PHoPolField.php` (load-order dependency for the `FieldType` backed enum).
