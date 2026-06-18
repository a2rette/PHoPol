<?php

declare(strict_types=1);

namespace CobPhp;

//================================================================
// CobPhpRecord — runtime record object
//
// Backed by an FFI uint8_t[] buffer.
// When attach()ed to external bytes, no copy is made —
// the record is purely a typed VIEW over the raw memory.
//
// Field access via get() / set() dispatches to type-specific
// encode/decode routines that read/write directly into the buffer.
//
// Occurs access via cell($fieldName, $idx) returns a small
// array-like object that proxies get/set for a specific entry.
//
// Condition names (88) are resolved via isCond() / setCond().
//================================================================
final class CobPhpRecord
{
    private \FFI\CData $buffer;       // the raw byte buffer
    private bool       $ownsBuffer;   // true = we allocated it

    /** @var array<string, CobPhpRecord> redefining records sharing our buffer */
    private array $redefiningRecords = [];

    private static ?\FFI $ffi = null;

    private static function ffi(): \FFI
    {
        return self::$ffi ??= \FFI::cdef('');
    }

    public function __construct(
        public readonly CobPhpLayout $layout,
        ?\FFI\CData $externalBuffer = null
    ) {
        if ($externalBuffer !== null) {
            $this->buffer     = $externalBuffer;
            $this->ownsBuffer = false;
        } else {
            $this->buffer     = self::ffi()->new("uint8_t[{$layout->totalLength}]", true);
            $this->ownsBuffer = true;
            // initialise to spaces (COBOL default for alphanumeric)
            \FFI::memset($this->buffer, 0x40, $layout->totalLength); // 0x40 = EBCDIC space
            // for ASCII environments use 0x20
            \FFI::memset($this->buffer, 0x20, $layout->totalLength);
        }
    }

    //------------------------------------------------------------
    // attach() — zero-copy bind to an external raw buffer
    // The string is the raw bytes read from a file / network.
    // No memcpy — we use FFI to get a pointer into the string data.
    //
    // Usage:
    //   $raw = fread($fd, $layout->totalLength);
    //   $record->attach($raw);
    //------------------------------------------------------------
    public function attach(string $rawBytes): void
    {
        $len = min(strlen($rawBytes), $this->layout->totalLength);
        // copy into our buffer (FFI strings require memcpy in PHP land —
        // true zero-copy needs a C extension; this is the Phase-1 approximation)
        \FFI::memcpy($this->buffer, $rawBytes, $len);

        // propagate to redefining records (same storage)
        foreach ($this->redefiningRecords as $rec) {
            $rec->attachBuffer($this->buffer);
        }
    }

    //------------------------------------------------------------
    // attachBuffer() — share another record's FFI buffer
    // Called internally when a REDEFINES relationship is registered
    //------------------------------------------------------------
    public function attachBuffer(\FFI\CData $buffer): void
    {
        $this->buffer     = $buffer;
        $this->ownsBuffer = false;
    }

    //------------------------------------------------------------
    // registerRedefines() — link a redefining record to this one
    // After attach(), the redefining record sees the same bytes.
    //------------------------------------------------------------
    public function registerRedefines(CobPhpRecord $other): void
    {
        $this->redefiningRecords[] = $other;
        $other->attachBuffer($this->buffer);
    }

    //------------------------------------------------------------
    // get($fieldName) — read a field value as a PHP value
    //------------------------------------------------------------
    public function get(string $fieldName): mixed
    {
        $field = $this->layout->getField($fieldName);
        if ($field === null) {
            throw new \RuntimeException(
                "Field '$fieldName' not found in layout '{$this->layout->name}'"
            );
        }
        return $this->decode($field, $field->offset);
    }

    //------------------------------------------------------------
    // set($fieldName, $value) — write a PHP value into the buffer
    //------------------------------------------------------------
    public function set(string $fieldName, mixed $value): void
    {
        $field = $this->layout->getField($fieldName);
        if ($field === null) {
            throw new \RuntimeException(
                "Field '$fieldName' not found in layout '{$this->layout->name}'"
            );
        }
        $this->encode($field, $field->offset, $value);
    }

    //------------------------------------------------------------
    // cell($groupFieldPrefix, $idx) — access one occurs entry
    // Returns a CobPhpCell proxy for that entry's sub-fields.
    //
    // Usage:  $record->cell('monthEntry', 3)->get('monthName')
    //         $record->cell('monthEntry', 3)->set('monthDays', 31)
    //------------------------------------------------------------
    public function cell(string $groupPrefix, int $idx): CobPhpCell
    {
        // find the entry size by looking up the first sub-field of this group
        // sub-fields are stored as  groupPrefix[*]_subFieldName
        $entrySize = 0;
        $subFields = [];

        foreach ($this->layout->allFields() as $name => $field) {
            if (str_starts_with($name, $groupPrefix . '[*]_')) {
                $subFields[] = $field;
                if ($entrySize === 0) {
                    $entrySize = $field->entrySize;
                }
            }
        }

        if (empty($subFields)) {
            throw new \RuntimeException(
                "No occurs sub-fields found for '$groupPrefix' in '{$this->layout->name}'"
            );
        }

        $baseOffset = $subFields[0]->offset + ($idx - 1) * $entrySize;
        return new CobPhpCell($this, $subFields, $baseOffset, $entrySize, $groupPrefix);
    }

    //------------------------------------------------------------
    // isCond($conditionName) — test a condition name (88)
    // Searches all fields for the named condition, evaluates it.
    //------------------------------------------------------------
    public function isCond(string $condName): bool
    {
        foreach ($this->layout->allFields() as $field) {
            foreach ($field->conditions as $name => [$values, $range]) {
                if ($name !== $condName) continue;

                $raw = $this->decode($field, $field->offset);

                if ($values !== null) {
                    return in_array(trim((string)$raw), $values, true);
                }
                if ($range !== null) {
                    $num = (int)$raw;
                    return $num >= $range[0] && $num <= $range[1];
                }
            }
        }
        throw new \RuntimeException(
            "Condition name '$condName' not found in layout '{$this->layout->name}'"
        );
    }

    //------------------------------------------------------------
    // setCond($conditionName, true) — SET 88-name TO TRUE
    // Writes the first listed value back into the parent field.
    //------------------------------------------------------------
    public function setCond(string $condName, bool $state): void
    {
        foreach ($this->layout->allFields() as $field) {
            foreach ($field->conditions as $name => [$values, $range]) {
                if ($name !== $condName) continue;

                if ($state) {
                    // SET TO TRUE — write first listed value
                    if ($values !== null) {
                        $this->encode($field, $field->offset, $values[0]);
                    } elseif ($range !== null) {
                        $this->encode($field, $field->offset, $range[0]);
                    }
                } else {
                    // SET TO FALSE — write LOW_VALUES
                    \FFI::memset(
                        $this->buffer + $field->offset,
                        0x00,
                        $field->length
                    );
                }
                return;
            }
        }
        throw new \RuntimeException(
            "Condition name '$condName' not found in layout '{$this->layout->name}'"
        );
    }

    //------------------------------------------------------------
    // rawBytes() — export the buffer as a PHP string (for file write)
    //------------------------------------------------------------
    public function rawBytes(): string
    {
        return \FFI::string($this->buffer, $this->layout->totalLength);
    }

    //------------------------------------------------------------
    // dump() — hex + ASCII dump of the buffer, for debugging
    //------------------------------------------------------------
    public function dump(): string
    {
        $raw   = $this->rawBytes();
        $lines = [];
        for ($i = 0; $i < strlen($raw); $i += 16) {
            $chunk = substr($raw, $i, 16);
            $hex   = implode(' ', array_map('bin2hex', str_split($chunk)));
            $asc   = preg_replace('/[^\x20-\x7e]/', '.', $chunk);
            $lines[] = sprintf('%04x  %-47s  %s', $i, $hex, $asc);
        }
        return implode("\n", $lines) . "\n";
    }

    //============================================================
    // INTERNAL: decode raw bytes → PHP value
    //============================================================
    public function decode(CobPhpField $field, int $offset): mixed
    {
        return match($field->type) {
            FieldType::Display => $this->decodeDisplay($field, $offset),
            FieldType::Binary,
            FieldType::Native  => $this->decodeBinary($field, $offset),
            FieldType::Packed  => $this->decodePacked($field, $offset),
            FieldType::Float32 => $this->decodeFloat($offset, 4),
            FieldType::Float64 => $this->decodeFloat($offset, 8),
        };
    }

    //============================================================
    // INTERNAL: encode PHP value → raw bytes
    //============================================================
    public function encode(CobPhpField $field, int $offset, mixed $value): void
    {
        match($field->type) {
            FieldType::Display => $this->encodeDisplay($field, $offset, $value),
            FieldType::Binary,
            FieldType::Native  => $this->encodeBinary($field, $offset, $value),
            FieldType::Packed  => $this->encodePacked($field, $offset, $value),
            FieldType::Float32 => $this->encodeFloat($offset, 4, (float)$value),
            FieldType::Float64 => $this->encodeFloat($offset, 8, (float)$value),
        };
    }

    //------------------------------------------------------------
    // DISPLAY decode/encode
    // Numeric: each byte = ASCII digit (0x30..0x39), sign in last nibble
    // Alpha:   plain ASCII string, space-padded
    //------------------------------------------------------------

    private function decodeDisplay(CobPhpField $field, int $offset): string|int|float
    {
        $raw = \FFI::string($this->buffer + $offset, $field->length);

        if (! $field->isNumeric()) {
            // alphanumeric — return as-is (right-trim for convenience)
            return $raw;
        }

        // numeric display — strip sign byte if present
        $digits = $raw;
        $sign   = 1;

        if ($field->isSigned()) {
            if ($field->flags & FieldFlags::SIGN_SEPARATE) {
                // first byte is '+' or '-'
                $sign   = ($digits[0] === '-') ? -1 : 1;
                $digits = substr($digits, 1);
            } else {
                // last nibble encodes sign (EBCDIC/ASCII zoned)
                $lastByte = ord($digits[strlen($digits) - 1]);
                $sign     = (($lastByte & 0xF0) === 0xD0) ? -1 : 1;
                // normalise last digit
                $digits   = substr($digits, 0, -1) . chr(0x30 | ($lastByte & 0x0F));
            }
        }

        $numStr = ltrim($digits, '0') ?: '0';

        if ($field->decimals > 0) {
            $intPart = substr($numStr, 0, strlen($numStr) - $field->decimals) ?: '0';
            $decPart = substr($numStr, -$field->decimals);
            return (float)($sign * (float)($intPart . '.' . $decPart));
        }

        return $sign * (int)$numStr;
    }

    private function encodeDisplay(CobPhpField $field, int $offset, mixed $value): void
    {
        if (! $field->isNumeric()) {
            // alphanumeric — left-align, space-pad or truncate
            $str = str_pad(substr((string)$value, 0, $field->length), $field->length, ' ');
            for ($i = 0; $i < $field->length; $i++) {
                $this->buffer[$offset + $i] = ord($str[$i]);
            }
            return;
        }

        // numeric display
        $sign    = ($value < 0) ? -1 : 1;
        $abs     = abs((float)$value);

        if ($field->decimals > 0) {
            $formatted = number_format($abs, $field->decimals, '', '');
        } else {
            $formatted = (string)(int)$abs;
        }

        $totalDigits = $field->digits + $field->decimals;
        $formatted   = str_pad($formatted, $totalDigits, '0', STR_PAD_LEFT);
        $formatted   = substr($formatted, -$totalDigits); // truncate if overflow

        $len = $field->length;
        if ($field->isSigned() && ($field->flags & FieldFlags::SIGN_SEPARATE)) {
            $this->buffer[$offset] = ord($sign < 0 ? '-' : '+');
            $offset++;
            $len--;
        }

        for ($i = 0; $i < $len; $i++) {
            $this->buffer[$offset + $i] = ord($formatted[$i]);
        }

        // zoned sign in last nibble (non-separate)
        if ($field->isSigned() && ! ($field->flags & FieldFlags::SIGN_SEPARATE)) {
            $lastIdx  = $offset + $len - 1;
            $lastByte = $this->buffer[$lastIdx];
            if ($sign < 0) {
                $this->buffer[$lastIdx] = ($lastByte & 0x0F) | 0xD0; // negative zone
            }
        }
    }

    //------------------------------------------------------------
    // BINARY decode/encode — two's complement, big-endian (mainframe)
    // For little-endian (x86) use NATIVE — handled identically here
    // since PHP's pack/unpack handles endianness
    //------------------------------------------------------------

    private function decodeBinary(CobPhpField $field, int $offset): int
    {
        $raw = \FFI::string($this->buffer + $offset, $field->length);

        return match($field->length) {
            2 => $field->isSigned()
                    ? unpack('s', $raw)[1]   // signed 16-bit
                    : unpack('S', $raw)[1],  // unsigned 16-bit
            4 => $field->isSigned()
                    ? unpack('l', $raw)[1]
                    : unpack('L', $raw)[1],
            8 => $field->isSigned()
                    ? unpack('q', $raw)[1]
                    : unpack('Q', $raw)[1],
            default => 0,
        };
    }

    private function encodeBinary(CobPhpField $field, int $offset, mixed $value): void
    {
        $packed = match($field->length) {
            2 => pack($field->isSigned() ? 's' : 'S', (int)$value),
            4 => pack($field->isSigned() ? 'l' : 'L', (int)$value),
            8 => pack($field->isSigned() ? 'q' : 'Q', (int)$value),
            default => "\x00",
        };
        for ($i = 0; $i < $field->length; $i++) {
            $this->buffer[$offset + $i] = ord($packed[$i]);
        }
    }

    //------------------------------------------------------------
    // PACKED-DECIMAL decode/encode (BCD)
    // Format: two digits per byte, last nibble = sign (C=+, D=-)
    //------------------------------------------------------------

    private function decodePacked(CobPhpField $field, int $offset): int|float
    {
        $digits = '';
        $sign   = 1;

        for ($i = 0; $i < $field->length; $i++) {
            $byte = $this->buffer[$offset + $i];
            $hi   = ($byte >> 4) & 0x0F;
            $lo   = $byte & 0x0F;

            if ($i === $field->length - 1) {
                // last byte: hi = last digit, lo = sign nibble
                $digits .= (string)$hi;
                $sign    = ($lo === 0x0D) ? -1 : 1;
            } else {
                $digits .= (string)$hi . (string)$lo;
            }
        }

        $num = (int)ltrim($digits, '0') ?: 0;

        if ($field->decimals > 0) {
            $intPart = (int)substr($digits, 0, strlen($digits) - $field->decimals);
            $decPart = substr($digits, -$field->decimals);
            return $sign * (float)($intPart . '.' . $decPart);
        }

        return $sign * $num;
    }

    private function encodePacked(CobPhpField $field, int $offset, mixed $value): void
    {
        $sign   = ($value < 0) ? 0x0D : 0x0C;
        $abs    = abs((float)$value);

        $totalNibbles = $field->digits + $field->decimals;

        if ($field->decimals > 0) {
            $formatted = number_format($abs, $field->decimals, '', '');
        } else {
            $formatted = (string)(int)$abs;
        }

        $formatted = str_pad($formatted, $totalNibbles, '0', STR_PAD_LEFT);
        $formatted = substr($formatted, -$totalNibbles);

        // pack digits into nibbles, sign in last nibble
        $nibbles = array_map('intval', str_split($formatted));
        $nibbles[] = $sign; // append sign nibble

        // pad to even if needed (length bytes = ceil((digits+1)/2))
        while (count($nibbles) < $field->length * 2) {
            array_unshift($nibbles, 0);
        }

        for ($i = 0; $i < $field->length; $i++) {
            $hi = $nibbles[$i * 2] & 0x0F;
            $lo = $nibbles[$i * 2 + 1] & 0x0F;
            $this->buffer[$offset + $i] = ($hi << 4) | $lo;
        }
    }

    //------------------------------------------------------------
    // FLOAT decode/encode
    //------------------------------------------------------------

    private function decodeFloat(int $offset, int $size): float
    {
        $raw = \FFI::string($this->buffer + $offset, $size);
        return ($size === 4)
            ? unpack('f', $raw)[1]
            : unpack('d', $raw)[1];
    }

    private function encodeFloat(int $offset, int $size, float $value): void
    {
        $packed = ($size === 4) ? pack('f', $value) : pack('d', $value);
        for ($i = 0; $i < $size; $i++) {
            $this->buffer[$offset + $i] = ord($packed[$i]);
        }
    }
}


//================================================================
// CobPhpCell — proxy for one entry in an OCCURS table
// Returned by CobPhpRecord::cell($name, $idx)
//================================================================
final class CobPhpCell
{
    /** @param CobPhpField[] $subFields */
    public function __construct(
        private readonly CobPhpRecord $record,
        private readonly array        $subFields,   // CobPhpField[]
        private readonly int          $baseOffset,  // offset of this entry's first byte
        private readonly int          $entrySize,
        private readonly string       $groupPrefix,
    ) {}

    public function get(string $subFieldName): mixed
    {
        $field = $this->findSubField($subFieldName);
        // sub-field offset is relative to group start; re-base to this cell
        $delta  = $field->offset - $this->subFields[0]->offset;
        return $this->record->decode($field, $this->baseOffset + $delta);
    }

    public function set(string $subFieldName, mixed $value): void
    {
        $field  = $this->findSubField($subFieldName);
        $delta  = $field->offset - $this->subFields[0]->offset;
        $this->record->encode($field, $this->baseOffset + $delta, $value);
    }

    private function findSubField(string $name): CobPhpField
    {
        foreach ($this->subFields as $sf) {
            $shortName = preg_replace('/^.*\[\*\]_/', '', $sf->name);
            if ($shortName === $name) return $sf;
        }
        throw new \RuntimeException(
            "Sub-field '$name' not found in occurs group '{$this->groupPrefix}'"
        );
    }
}
