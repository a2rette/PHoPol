<?php

declare(strict_types=1);

namespace PHoPol;

//================================================================
// PHoPolLevel — base class for any level(n) group item
//
// A typed view over a slice of an FFI byte buffer.
// Holds a reference to the buffer, a base offset within it,
// and a layout describing the fields it contains.
// All field access adds $baseOffset to $field->offset before
// reading or writing bytes.
//
// PHoPolLevel01 (level 01) extends this and adds buffer
// ownership, attach(), and REDEFINES wiring.
//================================================================
class PHoPolLevel
{
    public function __construct(
        public readonly PHoPolLayout $layout,
        protected \FFI\CData         $buffer,
        protected int                $baseOffset = 0,
    ) {}

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
        return $this->decode($field, $this->baseOffset + $field->offset);
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
        $this->encode($field, $this->baseOffset + $field->offset, $value);
    }

    //------------------------------------------------------------
    // cell($groupPrefix, $idx) — access one OCCURS entry
    //
    // Builds a sub-layout with cell-relative offsets and
    // short field names, then returns a PHoPolLevel view
    // over that slice of the shared buffer.
    //------------------------------------------------------------
    public function cell(string $groupPrefix, int $idx): PHoPolLevel
    {
        $subFields = [];
        $entrySize = 0;
        $groupBase = 0;

        foreach ($this->layout->allFields() as $field) {
            if (str_starts_with($field->name, $groupPrefix . '[*]_')) {
                if (empty($subFields)) {
                    $entrySize = $field->entrySize;
                    $groupBase = $field->offset;
                }
                $subFields[] = $field;
            }
        }

        if (empty($subFields)) {
            throw new \RuntimeException(
                "No occurs sub-fields found for '$groupPrefix' in '{$this->layout->name}'"
            );
        }

        $cellBaseOffset = $this->baseOffset + $groupBase + ($idx - 1) * $entrySize;

        $subLayout = new PHoPolLayout("{$groupPrefix}[{$idx}]", $entrySize, null, $this->layout->decimalPointIsComma);
        foreach ($subFields as $sf) {
            $shortName = (string)preg_replace('/^.*\[\*\]_/', '', $sf->name);
            $subLayout->addField(new PHoPolField(
                name:        $shortName,
                offset:      $sf->offset - $groupBase,
                length:      $sf->length,
                type:        $sf->type,
                digits:      $sf->digits,
                decimals:    $sf->decimals,
                flags:       $sf->flags,
                occursMin:   1,
                occursMax:   1,
                entrySize:   0,
                dependingOn: null,
                conditions:  $sf->conditions,
                editMask:    $sf->editMask,
            ));
        }

        return new PHoPolLevel($subLayout, $this->buffer, $cellBaseOffset);
    }

    //------------------------------------------------------------
    // isCond($conditionName) — test a condition name (88)
    //------------------------------------------------------------
    public function isCond(string $condName): bool
    {
        foreach ($this->layout->allFields() as $field) {
            foreach ($field->conditions as $name => [$values, $range]) {
                if ($name !== $condName) continue;

                $raw = $this->decode($field, $this->baseOffset + $field->offset);

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
    // setCond($conditionName, bool) — SET 88-name TO TRUE/FALSE
    //------------------------------------------------------------
    public function setCond(string $condName, bool $state): void
    {
        foreach ($this->layout->allFields() as $field) {
            foreach ($field->conditions as $name => [$values, $range]) {
                if ($name !== $condName) continue;

                $absOffset = $this->baseOffset + $field->offset;

                if ($state) {
                    if ($values !== null) {
                        $this->encode($field, $absOffset, $values[0]);
                    } elseif ($range !== null) {
                        $this->encode($field, $absOffset, $range[0]);
                    }
                } else {
                    \FFI::memset($this->buffer + $absOffset, 0x00, $field->length);
                }
                return;
            }
        }
        throw new \RuntimeException(
            "Condition name '$condName' not found in layout '{$this->layout->name}'"
        );
    }

    //------------------------------------------------------------
    // initialize() — COBOL INITIALIZE verb
    //
    // Iterates every elementary item in the layout and resets it
    // to its category default:
    //   binary / packed / float / numeric display → ZERO
    //   numeric-edited (editMask set)              → ZERO through mask
    //   alphanumeric / alphabetic display          → SPACES
    //
    // Works on any level, including cells returned by cell().
    //------------------------------------------------------------
    public function initialize(): void
    {
        foreach ($this->layout->allFields() as $field) {
            // For OCCURS fields the descriptor is a template for the first entry;
            // subsequent entries are at field->offset + i * entrySize.
            $count = $field->isOccurs() ? $field->occursMax : 1;
            for ($i = 0; $i < $count; $i++) {
                $absOffset = $this->baseOffset + $field->offset + $i * $field->entrySize;
                if ($this->isNumericCategory($field)) {
                    $this->encode($field, $absOffset, 0);
                } else {
                    \FFI::memset($this->buffer + $absOffset, 0x20, $field->length);
                }
            }
        }
    }

    private function isNumericCategory(PHoPolField $field): bool
    {
        return $field->isNumeric()
            || $field->editMask !== ''       // numeric-edited: MOVE ZERO applies mask
            || $field->type === FieldType::Binary
            || $field->type === FieldType::Native
            || $field->type === FieldType::Packed
            || $field->type === FieldType::Float32
            || $field->type === FieldType::Float64;
    }

    //------------------------------------------------------------
    // dump() — hex + ASCII dump of this level's buffer slice
    //------------------------------------------------------------
    public function dump(): string
    {
        $raw   = \FFI::string($this->buffer + $this->baseOffset, $this->layout->totalLength);
        $lines = [];
        for ($i = 0; $i < strlen($raw); $i += 16) {
            $chunk   = substr($raw, $i, 16);
            $hex     = implode(' ', array_map('bin2hex', str_split($chunk)));
            $asc     = (string)preg_replace('/[^\x20-\x7e]/', '.', $chunk);
            $lines[] = sprintf('%04x  %-47s  %s', $i, $hex, $asc);
        }
        return implode("\n", $lines) . "\n";
    }

    //============================================================
    // INTERNAL: decode/encode dispatch
    //============================================================

    protected function decode(PHoPolField $field, int $offset): mixed
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

    protected function encode(PHoPolField $field, int $offset, mixed $value): void
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
    // Numeric: zoned decimal (ASCII digit bytes, sign in last nibble)
    // Alpha:   plain ASCII string, space-padded
    //------------------------------------------------------------

    private function decodeDisplay(PHoPolField $field, int $offset): string|int|float
    {
        $raw = \FFI::string($this->buffer + $offset, $field->length);

        if (!$field->isNumeric()) {
            return $raw;
        }

        $digits = $raw;
        $sign   = 1;

        if ($field->isSigned()) {
            if ($field->flags & FieldFlags::SIGN_SEPARATE) {
                $sign   = ($digits[0] === '-') ? -1 : 1;
                $digits = substr($digits, 1);
            } else {
                $lastByte = ord($digits[strlen($digits) - 1]);
                $sign     = (($lastByte & 0xF0) === 0xD0) ? -1 : 1;
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

    private function encodeDisplay(PHoPolField $field, int $offset, mixed $value): void
    {
        // Edited picture: MOVE of a numeric value triggers automatic formatting,
        // exactly as in COBOL where MOVE numeric TO edited-pic applies the mask.
        if ($field->editMask !== '' && !is_string($value)) {
            $value = PHoPolRuntime::editFormat($field->editMask, $value, $this->layout->decimalPointIsComma);
        }

        if (!$field->isNumeric()) {
            $str = str_pad(substr((string)$value, 0, $field->length), $field->length, ' ');
            for ($i = 0; $i < $field->length; $i++) {
                $this->buffer[$offset + $i] = ord($str[$i]);
            }
            return;
        }

        $sign      = ($value < 0) ? -1 : 1;
        $abs       = abs((float)$value);
        $formatted = ($field->decimals > 0)
            ? number_format($abs, $field->decimals, '', '')
            : (string)(int)$abs;

        $totalDigits = $field->digits + $field->decimals;
        $formatted   = str_pad($formatted, $totalDigits, '0', STR_PAD_LEFT);
        $formatted   = substr($formatted, -$totalDigits);

        $len = $field->length;
        if ($field->isSigned() && ($field->flags & FieldFlags::SIGN_SEPARATE)) {
            $this->buffer[$offset] = ord($sign < 0 ? '-' : '+');
            $offset++;
            $len--;
        }

        for ($i = 0; $i < $len; $i++) {
            $this->buffer[$offset + $i] = ord($formatted[$i]);
        }

        if ($field->isSigned() && !($field->flags & FieldFlags::SIGN_SEPARATE)) {
            $lastIdx = $offset + $len - 1;
            if ($sign < 0) {
                $this->buffer[$lastIdx] = ($this->buffer[$lastIdx] & 0x0F) | 0xD0;
            }
        }
    }

    //------------------------------------------------------------
    // BINARY decode/encode — two's complement, big-endian
    //------------------------------------------------------------

    private function decodeBinary(PHoPolField $field, int $offset): int
    {
        $raw = \FFI::string($this->buffer + $offset, $field->length);
        return match($field->length) {
            2 => $field->isSigned() ? unpack('s', $raw)[1] : unpack('S', $raw)[1],
            4 => $field->isSigned() ? unpack('l', $raw)[1] : unpack('L', $raw)[1],
            8 => $field->isSigned() ? unpack('q', $raw)[1] : unpack('Q', $raw)[1],
            default => 0,
        };
    }

    private function encodeBinary(PHoPolField $field, int $offset, mixed $value): void
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
    // PACKED-DECIMAL decode/encode (COMP-3 / BCD)
    // Format: two digits per byte, last nibble = sign (C=+, D=-)
    //------------------------------------------------------------

    private function decodePacked(PHoPolField $field, int $offset): int|float
    {
        $digits = '';
        $sign   = 1;

        for ($i = 0; $i < $field->length; $i++) {
            $byte = $this->buffer[$offset + $i];
            $hi   = ($byte >> 4) & 0x0F;
            $lo   = $byte & 0x0F;

            if ($i === $field->length - 1) {
                $digits .= (string)$hi;
                $sign    = ($lo === 0x0D) ? -1 : 1;
            } else {
                $digits .= (string)$hi . (string)$lo;
            }
        }

        $num = (int)(ltrim($digits, '0') ?: '0');

        if ($field->decimals > 0) {
            $intPart = (int)substr($digits, 0, strlen($digits) - $field->decimals);
            $decPart = substr($digits, -$field->decimals);
            return $sign * (float)($intPart . '.' . $decPart);
        }

        return $sign * $num;
    }

    private function encodePacked(PHoPolField $field, int $offset, mixed $value): void
    {
        $sign         = ($value < 0) ? 0x0D : 0x0C;
        $abs          = abs((float)$value);
        $totalNibbles = $field->digits + $field->decimals;
        $formatted    = ($field->decimals > 0)
            ? number_format($abs, $field->decimals, '', '')
            : (string)(int)$abs;

        $formatted = str_pad($formatted, $totalNibbles, '0', STR_PAD_LEFT);
        $formatted = substr($formatted, -$totalNibbles);

        $nibbles   = array_map('intval', str_split($formatted));
        $nibbles[] = $sign;

        while (count($nibbles) < $field->length * 2) {
            array_unshift($nibbles, 0);
        }

        for ($i = 0; $i < $field->length; $i++) {
            $this->buffer[$offset + $i] = (($nibbles[$i * 2] & 0x0F) << 4)
                                        |  ($nibbles[$i * 2 + 1] & 0x0F);
        }
    }

    //------------------------------------------------------------
    // FLOAT decode/encode (COMP-1 / COMP-2)
    //------------------------------------------------------------

    private function decodeFloat(int $offset, int $size): float
    {
        $raw = \FFI::string($this->buffer + $offset, $size);
        return ($size === 4) ? unpack('f', $raw)[1] : unpack('d', $raw)[1];
    }

    private function encodeFloat(int $offset, int $size, float $value): void
    {
        $packed = ($size === 4) ? pack('f', $value) : pack('d', $value);
        for ($i = 0; $i < $size; $i++) {
            $this->buffer[$offset + $i] = ord($packed[$i]);
        }
    }
}
