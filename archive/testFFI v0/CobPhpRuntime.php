<?php

declare(strict_types=1);

namespace CobPhp;

//================================================================
// CobPhpRuntime — built-in functions that have no PHP equivalent
//
// These are the functions referenced in procedure.cobphp that
// cannot be expressed as plain PHP operators.
//
// In a future C extension these would be proper PHP functions
// registered via PHP_FUNCTION(). Here they are static methods
// on a helper class, aliased to global functions in bootstrap.php.
//================================================================

//----------------------------------------------------------------
// Figurative constants — resolved at bootstrap as PHP constants
//----------------------------------------------------------------
define('SPACES',      "\x20");   // space character (ASCII)
define('ZERO',        0);
define('ZEROS',       0);
define('HIGH_VALUES', "\xFF");
define('LOW_VALUES',  "\x00");
define('QUOTE',       '"');


//----------------------------------------------------------------
// CobPhpRuntime
//----------------------------------------------------------------
final class CobPhpRuntime
{
    //------------------------------------------------------------
    // move_corresponding($src, $dst)
    // Copies fields with matching names from $src into $dst.
    // Only elementary fields are copied (not group items).
    // COBOL: MOVE CORRESPONDING src TO dst
    //------------------------------------------------------------
    public static function moveCorresponding(
        CobPhpRecord $src,
        CobPhpRecord $dst
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
        CobPhpRecord $src,
        CobPhpRecord $dst
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
    // search_all($record, $tablePrefix, $keySubField, $value)
    // Binary search on a table declared with ordered_by asc/desc.
    // Returns 1-based index of matching entry, or null (AT END).
    // COBOL: SEARCH ALL table WHEN key = value
    //
    // Precondition: table must be sorted ascending on $keySubField.
    //------------------------------------------------------------
    public static function searchAll(
        CobPhpRecord $record,
        string       $tablePrefix,
        string       $keySubField,
        mixed        $searchValue
    ): ?int {
        // determine table size from layout
        $maxIdx = 0;
        foreach ($record->layout->allFields() as $name => $field) {
            if (str_starts_with($name, $tablePrefix . '[*]_')) {
                $maxIdx = $field->occursMax;
                break;
            }
        }

        if ($maxIdx === 0) {
            throw new \RuntimeException(
                "search_all: table '$tablePrefix' not found in '{$record->layout->name}'"
            );
        }

        // binary search
        $lo = 1;
        $hi = $maxIdx;

        while ($lo <= $hi) {
            $mid   = intdiv($lo + $hi, 2);
            $cell  = $record->cell($tablePrefix, $mid);
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
}


//================================================================
// Global function aliases — so procedure.cobphp can call
// move_corresponding() directly without the CobPhpRuntime:: prefix
//================================================================

function move_corresponding(CobPhpRecord $src, CobPhpRecord $dst): void
{
    CobPhpRuntime::moveCorresponding($src, $dst);
}

function add_corresponding(CobPhpRecord $src, CobPhpRecord $dst): void
{
    CobPhpRuntime::addCorresponding($src, $dst);
}

function search_all(
    CobPhpRecord $record,
    string       $tablePrefix,
    string       $keySubField,
    mixed        $searchValue
): ?int {
    return CobPhpRuntime::searchAll($record, $tablePrefix, $keySubField, $searchValue);
}
