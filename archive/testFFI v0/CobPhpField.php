<?php

declare(strict_types=1);

namespace CobPhp;

//================================================================
// Field types — mirrors COBOL USAGE / PIC categories
//================================================================
enum FieldType {
    case Display;       // PIC X(n) or PIC 9(n) — zoned decimal / char
    case Binary;        // USAGE BINARY / COMP  — two's complement
    case Packed;        // USAGE COMP-3         — packed decimal (BCD)
    case Float32;       // USAGE COMP-1         — single precision
    case Float64;       // USAGE COMP-2         — double precision
    case Native;        // USAGE COMP-5         — machine-native binary
}

//================================================================
// Flags bitmask
//================================================================
final class FieldFlags {
    const SIGNED         = 0x01;   // PIC S9(n)
    const NUMERIC        = 0x02;   // PIC 9(n) or S9(n)  vs PIC X(n)
    const SIGN_SEPARATE  = 0x04;   // SIGN LEADING SEPARATE
    const JUSTIFIED_RIGHT= 0x08;   // JUSTIFIED RIGHT
    const BLANK_ZERO     = 0x10;   // BLANK WHEN ZERO
    const SYNC           = 0x20;   // SYNCHRONIZED
}

//================================================================
// CobPhpField — compile-time descriptor for one elementary item
//
// Immutable once built by CobPhpParser.
// Stored in CobPhpLayout, looked up by field name.
//================================================================
final class CobPhpField
{
    public function __construct(
        public readonly string    $name,
        public readonly int       $offset,     // byte offset from record start
        public readonly int       $length,     // total byte length
        public readonly FieldType $type,
        public readonly int       $digits   = 0,  // PIC digit count
        public readonly int       $decimals = 0,  // decimal places after V
        public readonly int       $flags    = 0,  // FieldFlags bitmask

        // OCCURS support
        public readonly int       $occursMin   = 1,  // 1 for fixed
        public readonly int       $occursMax   = 1,  // 1 = not a table
        public readonly int       $entrySize   = 0,  // bytes per occurs entry
        public readonly ?string   $dependingOn = null, // ODO field name

        // Condition names (88) attached to this field
        // [ 'isOk' => [['OK'], null], 'isWarning' => [null, [1,4]] ]
        //    name  =>  [ values,      range ]
        public readonly array     $conditions  = [],
    ) {}

    public function isOccurs(): bool
    {
        return $this->occursMax > 1;
    }

    public function isSigned(): bool
    {
        return (bool)($this->flags & FieldFlags::SIGNED);
    }

    public function isNumeric(): bool
    {
        return (bool)($this->flags & FieldFlags::NUMERIC);
    }
}
