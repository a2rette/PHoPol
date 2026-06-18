<?php

declare(strict_types=1);

namespace CobPhp;

//================================================================
// CobPhpLayout — compile-time descriptor for one level(01) record
//
// Holds:
//   - the total byte length of the record
//   - a flat map of all fields (name → CobPhpField)
//   - the name of the record this one redefines (if any)
//
// Built once by CobPhpParser, shared across all instances
// of the same record type — like a C struct definition.
//================================================================
final class CobPhpLayout
{
    /** @var array<string, CobPhpField> */
    private array $fields = [];

    public function __construct(
        public readonly string  $name,
        public readonly int     $totalLength,
        public readonly ?string $redefines = null,           // name of redefined layout
        public readonly bool    $decimalPointIsComma = false, // SPECIAL-NAMES DECIMAL POINT IS COMMA
    ) {}

    public function addField(CobPhpField $field): void
    {
        $this->fields[$field->name] = $field;
    }

    public function getField(string $name): ?CobPhpField
    {
        return $this->fields[$name] ?? null;
    }

    /** @return array<string, CobPhpField> */
    public function allFields(): array
    {
        return $this->fields;
    }

    //------------------------------------------------------------
    // Dump layout for debugging — shows every field with its
    // offset, length, and type, like a COBOL DCLGEN listing
    //------------------------------------------------------------
    public function dump(): string
    {
        $lines   = [];
        $lines[] = sprintf("Layout: %s  (%d bytes)%s",
            $this->name,
            $this->totalLength,
            $this->redefines ? "  redefines {$this->redefines}" : ''
        );
        $lines[] = str_repeat('-', 72);
        $lines[] = sprintf("  %-30s %6s %6s  %-10s  %s",
            'Field', 'Offset', 'Len', 'Type', 'Flags');
        $lines[] = str_repeat('-', 72);

        foreach ($this->fields as $field) {
            $typeStr  = $field->type->name;
            if ($field->digits > 0) {
                $typeStr .= "({$field->digits}";
                if ($field->decimals > 0) {
                    $typeStr .= ",{$field->decimals}";
                }
                $typeStr .= ')';
            }

            $flagStr = '';
            if ($field->isSigned())  $flagStr .= 'S';
            if ($field->isOccurs())  $flagStr .= " occurs({$field->occursMin}..{$field->occursMax})";

            $lines[] = sprintf("  %-30s %6d %6d  %-10s  %s",
                $field->name,
                $field->offset,
                $field->length,
                $typeStr,
                $flagStr
            );

            foreach ($field->conditions as $condName => $cond) {
                [$values, $range] = $cond;
                if ($values !== null) {
                    $lines[] = sprintf("    %-28s              when == [%s]",
                        "→ \${$condName}",
                        implode(', ', array_map(fn($v) => "'$v'", $values))
                    );
                } elseif ($range !== null) {
                    $lines[] = sprintf("    %-28s              when in %s..%s",
                        "→ \${$condName}",
                        $range[0], $range[1]
                    );
                }
            }
        }

        $lines[] = str_repeat('-', 72);
        return implode("\n", $lines) . "\n";
    }
}
