<?php

declare(strict_types=1);

namespace PHoPol;

//================================================================
// PHoPolRuntime — built-in functions that have no PHP equivalent
//
// These are the functions referenced in procedure.phopol that
// cannot be expressed as plain PHP operators.
//
// In a future C extension these would be proper PHP functions
// registered via PHP_FUNCTION(). Here they are static methods
// on a helper class, aliased to global functions in bootstrap.php.
//================================================================

//----------------------------------------------------------------
// Figurative constants — resolved at bootstrap as PHP constants
//----------------------------------------------------------------
define('SPACE',        "\x20");
define('SPACES',       "\x20");
define('ZERO',         0);
define('ZEROS',        0);
define('ZEROES',       0);
define('HIGH_VALUE',   "\xFF");
define('HIGH_VALUES',  "\xFF");
define('LOW_VALUE',    "\x00");
define('LOW_VALUES',   "\x00");
define('QUOTE',        '"');
define('QUOTES',       '"');


//----------------------------------------------------------------
// PHoPolRuntime
//----------------------------------------------------------------
final class PHoPolRuntime
{
    //------------------------------------------------------------
    // move_corresponding($src, $dst)
    // Copies fields with matching names from $src into $dst.
    // Only elementary fields are copied (not group items).
    // COBOL: MOVE CORRESPONDING src TO dst
    //------------------------------------------------------------
    public static function moveCorresponding(
        PHoPolLevel $src,
        PHoPolLevel $dst
    ): void {
        foreach ($src->layout->allFields() as $name => $srcField) {
            $dstField = $dst->layout->getField($name);
            if ($dstField === null) continue;

            // only copy leaf fields (occursMax == 1 for simplicity)
            if ($srcField->isOccurs() || $dstField->isOccurs()) continue;

            $value = $src->get($name);
            $dst->set($name, $value);
        }
    }

    //------------------------------------------------------------
    // add_corresponding($src, $dst)
    // Adds numeric fields with matching names from $src into $dst.
    // COBOL: ADD CORRESPONDING src TO dst
    //------------------------------------------------------------
    public static function addCorresponding(
        PHoPolLevel $src,
        PHoPolLevel $dst
    ): void {
        foreach ($src->layout->allFields() as $name => $srcField) {
            if (! $srcField->isNumeric()) continue;

            $dstField = $dst->layout->getField($name);
            if ($dstField === null || ! $dstField->isNumeric()) continue;
            if ($srcField->isOccurs() || $dstField->isOccurs()) continue;

            $srcVal = $src->get($name);
            $dstVal = $dst->get($name);
            $dst->set($name, $dstVal + $srcVal);
        }
    }

    //------------------------------------------------------------
    // search_all($level, $tablePrefix, $keySubField, $value)
    // Binary search on a table declared with ordered_by asc/desc.
    // Returns 1-based index of matching entry, or null (AT END).
    // COBOL: SEARCH ALL table WHEN key = value
    //
    // Precondition: table must be sorted ascending on $keySubField.
    //------------------------------------------------------------
    public static function searchAll(
        PHoPolLevel $level,
        string       $tablePrefix,
        string       $keySubField,
        mixed        $searchValue
    ): ?int {
        // determine table size from layout
        $maxIdx = 0;
        foreach ($level->layout->allFields() as $name => $field) {
            if (str_starts_with($name, $tablePrefix . '[*]_')) {
                $maxIdx = $field->occursMax;
                break;
            }
        }

        if ($maxIdx === 0) {
            throw new \RuntimeException(
                "search_all: table '$tablePrefix' not found in '{$level->layout->name}'"
            );
        }

        // binary search
        $lo = 1;
        $hi = $maxIdx;

        while ($lo <= $hi) {
            $mid   = intdiv($lo + $hi, 2);
            $cell  = $level->cell($tablePrefix, $mid);
            $cellVal = $cell->get($keySubField);

            $cmp = $cellVal <=> $searchValue;

            if ($cmp === 0) {
                return $mid;   // found — return 1-based index
            } elseif ($cmp < 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return null;   // AT END — not found
    }

    //------------------------------------------------------------
    // inspect_tally($value, $target) → int
    // Count occurrences of $target in $value.
    // COBOL: INSPECT field TALLYING tally FOR ALL target
    //------------------------------------------------------------
    public static function inspectTally(string $value, string $target): int
    {
        return substr_count($value, $target);
    }

    //------------------------------------------------------------
    // inspect_tally_leading($value, $target) → int
    // Count leading occurrences of $target in $value.
    // COBOL: INSPECT field TALLYING tally FOR LEADING target
    //------------------------------------------------------------
    public static function inspectTallyLeading(string $value, string $target): int
    {
        $count = 0;
        $len   = strlen($target);
        $pos   = 0;
        while (substr($value, $pos, $len) === $target) {
            $count++;
            $pos += $len;
        }
        return $count;
    }

    //------------------------------------------------------------
    // inspect_replace($value, $from, $to) → string
    // COBOL: INSPECT field REPLACING ALL from BY to
    //------------------------------------------------------------
    public static function inspectReplace(string $value, string $from, string $to): string
    {
        return str_replace($from, $to, $value);
    }

    //------------------------------------------------------------
    // inspect_convert($value, $fromChars, $toChars) → string
    // COBOL: INSPECT field CONVERTING fromChars TO toChars
    //------------------------------------------------------------
    public static function inspectConvert(
        string $value,
        string $fromChars,
        string $toChars
    ): string {
        return strtr($value, $fromChars, $toChars);
    }

    //------------------------------------------------------------
    // editFormat($mask, $value) → string
    // Apply a COBOL edited picture mask to a numeric value.
    //
    // Supported mask characters:
    //   9  — mandatory digit
    //   Z  — zero-suppress digit (leading zeros → space)
    //   $  — floating currency sign
    //   *  — asterisk fill (like Z but fills with *)
    //   ,  — comma insertion (suppressed in zero-suppress zone)
    //   .  — decimal point insertion
    //   /  — slash insertion (dates, etc.)
    //   B  — blank insertion
    //   +  — sign: '+' positive, '-' negative
    //   -  — sign: ' ' positive, '-' negative
    //   CR / DB — suffix (shown only when negative)
    //   V  — implied decimal (no byte; splits int/dec)
    //------------------------------------------------------------
    public static function editFormat(string $mask, mixed $value, bool $decimalPointIsComma = false): string
    {
        // With DECIMAL POINT IS COMMA the mask is already written in the
        // program's locale (',' = decimal separator, '.' = thousands inserter).
        // We just need to look for the right characters at each decision point.
        $decimalCh   = $decimalPointIsComma ? ',' : '.';
        $thousandsCh = $decimalPointIsComma ? '.' : ',';

        $isNeg  = ($value < 0);
        $absVal = abs((float)$value);
        $upper  = strtoupper($mask);

        // Split mask at printed decimal separator or implied (V) decimal point
        $vPos     = strpos($upper, 'V');
        $splitPos = strpos($upper, strtoupper($decimalCh));
        $splitAt  = ($splitPos !== false) ? $splitPos : (($vPos !== false) ? $vPos : -1);

        $intMask = ($splitAt >= 0) ? substr($upper, 0, $splitAt) : $upper;
        $decMask = ($splitAt >= 0) ? substr($upper, $splitAt + 1) : '';
        $hasDot  = ($splitPos !== false); // true when a printed decimal separator exists

        // Strip CR/DB suffix
        $suffix = '';
        if (strlen($decMask) >= 2) {
            $end = substr($decMask, -2);
            if ($end === 'CR' || $end === 'DB') {
                $suffix  = $end;
                $decMask = substr($decMask, 0, -2);
            }
        }

        // Count digit slots
        $decSlots = (int)preg_match_all('/[9Z]/', $decMask);
        $intSlots = (int)preg_match_all('/[9Z$*]/', $intMask);
        if ($intSlots === 0) $intSlots = 1;

        // Build integer and decimal digit strings
        if ($decSlots > 0) {
            $fmt    = number_format($absVal, $decSlots, '.', '');
            [$intStr, $decStr] = explode('.', $fmt);
        } else {
            $intStr = (string)(int)round($absVal);
            $decStr = '';
        }
        $intStr = str_pad($intStr, $intSlots, '0', STR_PAD_LEFT);
        $intStr = substr($intStr, -$intSlots);          // truncate overflow
        $decStr = str_pad(substr($decStr, 0, $decSlots), $decSlots, '0', STR_PAD_RIGHT);

        // First significant digit index in $intStr.
        // Default: $intSlots (beyond last) so pure-Z masks suppress all digits
        // when value is zero. '9' outputs unconditionally regardless of $firstSig.
        $firstSig = $intSlots;
        for ($i = 0; $i < $intSlots; $i++) {
            if ($intStr[$i] !== '0') { $firstSig = $i; break; }
        }

        // Walk integer mask
        $out       = '';
        $digitIdx  = 0;
        $suppress  = true;
        $floatDone = false;

        for ($i = 0; $i < strlen($intMask); $i++) {
            $ch = $intMask[$i];
            switch ($ch) {
                case '9':
                    $out      .= $intStr[$digitIdx++];
                    $suppress  = false;
                    break;

                case 'Z':
                    if ($suppress && $digitIdx < $firstSig) {
                        $out .= ' ';
                    } else {
                        $out      .= $intStr[$digitIdx];
                        $suppress  = false;
                    }
                    $digitIdx++;
                    break;

                case '$':
                    if ($suppress && $digitIdx < $firstSig - 1) {
                        $out .= ' ';
                    } elseif ($suppress && $digitIdx === $firstSig - 1 && !$floatDone) {
                        // float $ lands at the position just before first significant digit
                        $out      .= '$';
                        $floatDone = true;
                        $suppress  = false;
                    } else {
                        $out      .= $intStr[$digitIdx];
                        $suppress  = false;
                    }
                    $digitIdx++;
                    break;

                case '*':
                    if ($suppress && $digitIdx < $firstSig) {
                        $out .= '*';
                    } else {
                        $out      .= $intStr[$digitIdx];
                        $suppress  = false;
                    }
                    $digitIdx++;
                    break;

                case ',':
                case '.':
                    // Either character can be the thousands inserter depending on locale
                    if ($ch === $thousandsCh) {
                        $out .= $suppress ? ' ' : $thousandsCh;
                    } else {
                        $out .= $ch; // decimal sep appearing in intMask shouldn't happen
                    }
                    break;

                case '+':
                    $out .= $isNeg ? '-' : '+';
                    break;

                case '-':
                    $out .= $isNeg ? '-' : ' ';
                    break;

                case 'B':
                    $out .= ' ';
                    break;

                default:
                    $out .= $intMask[$i];   // '/' and other insertion characters
                    break;
            }
        }

        // Decimal part
        // Output decimal digits whenever a decimal split exists (. or V).
        // With '.', print the period character first.
        // With 'V' (virgule virtuelle), no character is printed but digits still flow.
        if ($splitAt >= 0 && $decMask !== '') {
            if ($hasDot) {
                $out .= $decimalCh;
            }
            $dIdx = 0;
            for ($i = 0; $i < strlen($decMask); $i++) {
                $ch   = $decMask[$i];
                $out .= in_array($ch, ['9', 'Z']) ? $decStr[$dIdx++] : $ch;
            }
        }

        // Trailing sign suffix
        if ($suffix !== '') {
            $out .= $isNeg ? $suffix : '  ';
        }

        return $out;
    }
}


//================================================================
// Global function aliases — so procedure.phopol can call
// move_corresponding() directly without the PHoPolRuntime:: prefix
//================================================================

function move_corresponding(PHoPolLevel $src, PHoPolLevel $dst): void
{
    PHoPolRuntime::moveCorresponding($src, $dst);
}

function add_corresponding(PHoPolLevel $src, PHoPolLevel $dst): void
{
    PHoPolRuntime::addCorresponding($src, $dst);
}

function search_all(
    PHoPolLevel $level,
    string       $tablePrefix,
    string       $keySubField,
    mixed        $searchValue
): ?int {
    return PHoPolRuntime::searchAll($level, $tablePrefix, $keySubField, $searchValue);
}

function edit_format(string $mask, mixed $value, bool $decimalPointIsComma = false): string
{
    return PHoPolRuntime::editFormat($mask, $value, $decimalPointIsComma);
}

// INITIALIZE accepts one or more levels (mirrors COBOL: INITIALIZE A B C)
function initialize(PHoPolLevel ...$levels): void
{
    foreach ($levels as $level) {
        $level->initialize();
    }
}
