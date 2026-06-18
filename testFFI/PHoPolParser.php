<?php

declare(strict_types=1);

namespace PHoPol;

//================================================================
// PHoPolParser
//
// Parses the level()/occurs()/type declarations from a .phopol
// file and produces a map of  name → PHoPolLayout.
//
// This is a Phase-1 prototype parser — it handles the subset of
// PHP/COBOL syntax needed to prove the memory model:
//
//   namespace wss { ... }
//   level(01) $Name { ... }
//   level(01) $Name redefines $Other { ... }
//   type [modifiers] $name;
//   type $name occurs(n, index: $idx);
//   level(n) $name occurs(n, index: $idx) { ... }
//   standalone type $name = value;
//   when $f == 'v' : bool $cond;
//   when $f in lo..hi : bool $cond;
//
// It does NOT yet handle:
//   filler, alias (66), justify, blank_zero, sync, global, external
//   (these affect display / alignment, not memory layout)
//================================================================
final class PHoPolParser
{
    /** @var array<string, PHoPolLayout> */
    private array $layouts = [];

    private array $tokens             = [];
    private int   $pos                = 0;
    private bool  $decimalPointIsComma = false;

    //------------------------------------------------------------
    // Public API
    //------------------------------------------------------------

    /**
     * Parse a .phopol source string.
     * Returns map of layoutName => PHoPolLayout.
     *
     * @return array<string, PHoPolLayout>
     */
    public function parse(string $source): array
    {
        $this->layouts             = [];
        $this->tokens              = $this->tokenize($source);
        $this->pos                 = 0;
        $this->decimalPointIsComma = false;

        // skip to namespace wss block
        while (! $this->atEnd()) {
            if ($this->peek() === 'namespace') {
                $this->consume('namespace');
                $this->consumeIdentifier(); // wss (or any name)
                $this->consume('{');
                $this->parseNamespaceBody();
                $this->consume('}');
            } else {
                $this->advance();
            }
        }

        return $this->layouts;
    }

    //------------------------------------------------------------
    // Tokenizer — strips comments, collapses whitespace
    //------------------------------------------------------------

    /** @return array<string> */
    private function tokenize(string $source): array
    {
        // strip // line comments
        $source = preg_replace('/\/\/[^\n]*/', '', $source);
        // strip /* */ block comments
        $source = preg_replace('/\/\*.*?\*\//s', '', $source);

        // split on boundaries: punctuation, words, numbers, strings
        preg_match_all(
            '/
                \'[^\']*\'          # single-quoted string
              | "[^"]*"             # double-quoted string
              | [0-9]+\.[0-9]+      # float
              | [0-9]+              # integer
              | \.\.                # range operator
              | ==?                 # equality operator (==) or initialiser (=)
              | edited              # edited picture keyword — must precede general identifier
              | [{}();,:<>]         # single-char punctuation
              | [+\-]               # signs
              | \$[a-zA-Z_][a-zA-Z0-9_]*   # $variable
              | [a-zA-Z_][a-zA-Z0-9_<>,]*   # identifier (may include <> for types)
            /x',
            $source,
            $matches
        );

        return $matches[0];
    }

    //------------------------------------------------------------
    // Token stream helpers
    //------------------------------------------------------------

    private function peek(int $ahead = 0): string
    {
        return $this->tokens[$this->pos + $ahead] ?? '';
    }

    private function advance(): string
    {
        return $this->tokens[$this->pos++] ?? '';
    }

    private function consume(string $expected): string
    {
        $tok = $this->advance();
        if ($tok !== $expected) {
            throw new \RuntimeException(
                "PHoPolParser: expected '$expected', got '$tok' at token {$this->pos}"
            );
        }
        return $tok;
    }

    private function consumeIdentifier(): string
    {
        $tok = $this->advance();
        if ($tok === '') {
            throw new \RuntimeException("PHoPolParser: expected identifier, got EOF");
        }
        return $tok;
    }

    private function atEnd(): bool
    {
        return $this->pos >= count($this->tokens);
    }

    //------------------------------------------------------------
    // Parse namespace body
    //------------------------------------------------------------

    private function parseNamespaceBody(): void
    {
        while (! $this->atEnd() && $this->peek() !== '}') {
            $tok = $this->peek();

            if ($tok === 'level') {
                $layout = $this->parseLevel01();
                if ($layout !== null) {
                    $this->layouts[$layout->name] = $layout;
                }
            } elseif ($tok === 'standalone') {
                // standalone fields become a synthetic level(77) layout
                $field  = $this->parseStandalone();
                $layout = new PHoPolLayout('__standalone_' . $field->name, $field->length,
                    null, $this->decimalPointIsComma);
                $layout->addField($field);
                $this->layouts[$layout->name] = $layout;
            } elseif ($tok === 'decimal_point') {
                // SPECIAL-NAMES: DECIMAL POINT IS COMMA
                $this->advance(); // consume 'decimal_point'
                $this->consume('is');
                $this->consume('comma');
                if ($this->peek() === ';') $this->advance();
                $this->decimalPointIsComma = true;
            } elseif (in_array($tok, ['global', 'external', 'shared'])) {
                // skip modifier, then parse the level
                $this->advance();
                $layout = $this->parseLevel01();
                if ($layout !== null) {
                    $this->layouts[$layout->name] = $layout;
                }
            } else {
                $this->advance(); // skip unknown token
            }
        }
    }

    //------------------------------------------------------------
    // Parse level(01) $Name [redefines $Other] { ... }
    //------------------------------------------------------------

    private function parseLevel01(): ?PHoPolLayout
    {
        $this->consume('level');
        $this->consume('(');
        $this->consumeIdentifier(); // level number e.g. 01
        $this->consume(')');

        $name = $this->consumeIdentifier(); // $WsName
        $name = ltrim($name, '$');

        $redefines = null;
        if ($this->peek() === 'redefines') {
            $this->advance();
            $other     = $this->consumeIdentifier();
            $redefines = ltrim($other, '$');
        }

        $this->consume('{');

        // parse fields, tracking current byte offset
        $fields      = [];
        $offset      = 0;
        $this->parseGroupBody($fields, $offset);

        $this->consume('}');

        $layout = new PHoPolLayout($name, $offset, $redefines, $this->decimalPointIsComma);
        foreach ($fields as $field) {
            $layout->addField($field);
        }

        return $layout;
    }

    //------------------------------------------------------------
    // Parse the body of a group { } recursively
    // $fields is filled in-place; $offset is updated
    //------------------------------------------------------------

    /** @param PHoPolField[] $fields */
    private function parseGroupBody(array &$fields, int &$offset): void
    {
        while (! $this->atEnd() && $this->peek() !== '}') {
            $tok = $this->peek();

            if ($tok === 'level') {
                // nested group — recurse
                $this->advance(); // consume 'level'
                $this->consume('(');
                $this->consumeIdentifier(); // level number
                $this->consume(')');

                $subName = $this->consumeIdentifier(); // $name or nothing
                $subName = ltrim($subName, '$');

                // check for occurs
                $occursMin = 1;
                $occursMax = 1;
                $entrySize = 0;
                $dependingOn = null;

                if ($this->peek() === 'occurs') {
                    [$occursMin, $occursMax, $dependingOn] = $this->parseOccursClause();
                }

                $this->consume('{');
                $subFields  = [];
                $subOffset  = 0;
                $this->parseGroupBody($subFields, $subOffset);
                $this->consume('}');

                $entrySize   = $subOffset;
                $totalLength = $entrySize * $occursMax;

                // add sub-fields with adjusted offsets
                foreach ($subFields as $sf) {
                    for ($i = 0; $i < $occursMax; $i++) {
                        $entryOffset = $offset + $i * $entrySize + $sf->offset;
                        // for occurs groups, we store each sub-field once
                        // with occursMax/entrySize so the level can compute
                        // [$i]->$subField at runtime
                        break; // store template only
                    }
                    // store the sub-field descriptor relative to group start
                    $fieldName = ($occursMax > 1)
                        ? "{$subName}[*]_{$sf->name}"
                        : $sf->name;

                    $fields[] = new PHoPolField(
                        name:          $fieldName,
                        offset:        $offset + $sf->offset,
                        length:        $sf->length,
                        type:          $sf->type,
                        digits:        $sf->digits,
                        decimals:      $sf->decimals,
                        flags:         $sf->flags,
                        occursMin:     $occursMax > 1 ? $occursMin : 1,
                        occursMax:     $sf->occursMax > 1 ? $sf->occursMax : ($occursMax > 1 ? $occursMax : 1),
                        entrySize:     $sf->occursMax > 1 ? $sf->entrySize : ($occursMax > 1 ? $entrySize : 0),
                        dependingOn:   $dependingOn,
                        conditions:    $sf->conditions,
                        editMask:      $sf->editMask,
                        initialValue:  $sf->initialValue,
                        initialIsFill: $sf->initialIsFill,
                    );
                }

                $offset += $totalLength;

            } elseif ($tok === 'when') {
                // condition name — attach to last field added
                $cond = $this->parseConditionName();
                if (! empty($fields)) {
                    $last      = array_pop($fields);
                    $conditions = $last->conditions;
                    $conditions[$cond['name']] = [$cond['values'], $cond['range']];
                    $fields[] = new PHoPolField(
                        name:          $last->name,
                        offset:        $last->offset,
                        length:        $last->length,
                        type:          $last->type,
                        digits:        $last->digits,
                        decimals:      $last->decimals,
                        flags:         $last->flags,
                        occursMin:     $last->occursMin,
                        occursMax:     $last->occursMax,
                        entrySize:     $last->entrySize,
                        dependingOn:   $last->dependingOn,
                        conditions:    $conditions,
                        editMask:      $last->editMask,
                        initialValue:  $last->initialValue,
                        initialIsFill: $last->initialIsFill,
                    );
                }

            } elseif ($tok === 'filler') {
                $this->advance();
                $field   = $this->parseTypeAndName('__filler_' . $offset, $offset);
                $offset += $field->length;
                // filler not added to $fields — unreferenceable

            } elseif ($tok === 'redefines') {
                // inline redefines — skip for now (same offset, just alternate view)
                $this->advance();
                $this->consume('(');
                $this->consumeIdentifier();
                $this->consume(')');
                $field   = $this->parseTypeAndName('__redef_' . $offset, $offset);
                // don't advance offset — same bytes
                $fields[] = $field;

            } elseif ($tok === 'alias') {
                // skip alias (66) declarations — no storage impact
                while (! $this->atEnd() && $this->peek() !== ';') {
                    $this->advance();
                }
                $this->advance(); // consume ';'

            } else {
                // elementary field declaration
                $field   = $this->parseTypeAndName(null, $offset);
                $offset += $field->length * max(1, $field->occursMax);
                $fields[] = $field;
            }
        }
    }

    //------------------------------------------------------------
    // Parse an elementary type + modifiers + $name + occurs? + ;
    // e.g.  string<10> $programName = 'SHOWCASE';
    //       int<9> binary $binInt;
    //       string<1> $flags occurs(10, index: $flagIdx);
    //------------------------------------------------------------

    private function parseTypeAndName(?string $forcedName, int $baseOffset): PHoPolField
    {
        // type token — may be  string<10>  or  int<9>  or  float32  etc.
        $typeTok  = $this->advance();
        $resolved = $this->resolveType($typeTok);
        [$type, $digits, $decimals, $length, $flags] = $resolved;
        $editMask = $resolved[5] ?? '';

        // modifiers: binary, comp, packed, native, sync, justify(...), blank_zero, signed_separate
        while (in_array($this->peek(), ['binary','comp','packed','native','sync',
                                         'justify','blank_zero','signed_separate'])) {
            $mod = $this->advance();
            if ($mod === 'packed') {
                $type   = FieldType::Packed;
                $length = (int)ceil(($digits + $decimals + 1) / 2);   // BCD storage
            } elseif (in_array($mod, ['binary','comp'])) {
                $type   = FieldType::Binary;
                $length = match(true) {
                    $digits <= 4  => 2,
                    $digits <= 9  => 4,
                    default       => 8,
                };
            } elseif ($mod === 'native') {
                $type   = FieldType::Native;
                $length = match(true) {
                    $digits <= 4  => 2,
                    $digits <= 9  => 4,
                    default       => 8,
                };
            } elseif ($mod === 'sync') {
                $flags |= FieldFlags::SYNC;
                // consume optional (left) or (right)
                if ($this->peek() === '(') {
                    $this->advance();
                    $this->consumeIdentifier();
                    $this->consume(')');
                }
            } elseif ($mod === 'justify') {
                $flags |= FieldFlags::JUSTIFIED_RIGHT;
                if ($this->peek() === '(') {
                    $this->advance();
                    $this->consumeIdentifier();
                    $this->consume(')');
                }
            } elseif ($mod === 'blank_zero') {
                $flags |= FieldFlags::BLANK_ZERO;
            } elseif ($mod === 'signed_separate') {
                $flags |= FieldFlags::SIGN_SEPARATE;
                $length += 1; // extra byte for sign character
            }
        }

        // field name
        $name = $forcedName ?? ltrim($this->consumeIdentifier(), '$');

        // optional occurs clause
        $occursMin   = 1;
        $occursMax   = 1;
        $entrySize   = 0;
        $dependingOn = null;

        if ($this->peek() === 'occurs') {
            [$occursMin, $occursMax, $dependingOn] = $this->parseOccursClause();
            $entrySize = $length;
        }

        // optional  = value  initialiser
        $initialValue  = null;
        $initialIsFill = false;
        if ($this->peek() === '=') {
            $this->advance();                    // consume '='
            $sign = '';
            if ($this->peek() === '-') { $sign = '-'; $this->advance(); }
            $raw = trim($this->advance(), "'\"");
            [$initialValue, $initialIsFill] = match (strtoupper($raw)) {
                'SPACE',       'SPACES'         => [' ',    true],
                'ZERO',        'ZEROS', 'ZEROES'=> [0,      false],
                'HIGH_VALUE',  'HIGH_VALUES'    => ["\xFF", true],
                'LOW_VALUE',   'LOW_VALUES'     => ["\x00", true],
                'QUOTE',       'QUOTES'         => ['"',    false],
                default => [
                    is_numeric($sign . $raw) ? ($sign . $raw + 0) : $sign . $raw,
                    false,
                ],
            };
        }

        // consume ';'
        if ($this->peek() === ';') {
            $this->advance();
        }

        return new PHoPolField(
            name:          $name,
            offset:        $baseOffset,
            length:        $length,
            type:          $type,
            digits:        $digits,
            decimals:      $decimals,
            flags:         $flags,
            occursMin:     $occursMin,
            occursMax:     $occursMax,
            entrySize:     $entrySize,
            dependingOn:   $dependingOn,
            editMask:      $editMask,
            initialValue:  $initialValue,
            initialIsFill: $initialIsFill,
        );
    }

    //------------------------------------------------------------
    // Resolve a type token into (FieldType, digits, decimals, byteLength, flags)
    //------------------------------------------------------------

    private function resolveType(string $tok): array
    {
        $flags = 0;

        // string<n>  →  Display, n bytes
        if (preg_match('/^string<(\d+)>$/', $tok, $m)) {
            return [FieldType::Display, 0, 0, (int)$m[1], $flags];
        }

        // alpha<n>  →  Display, n bytes
        if (preg_match('/^alpha<(\d+)>$/', $tok, $m)) {
            return [FieldType::Display, 0, 0, (int)$m[1], $flags];
        }

        // uint<n>  →  Display numeric unsigned
        if (preg_match('/^uint<(\d+)>$/', $tok, $m)) {
            $flags |= FieldFlags::NUMERIC;
            return [FieldType::Display, (int)$m[1], 0, (int)$m[1], $flags];
        }

        // int<n>  →  Display numeric signed
        if (preg_match('/^int<(\d+)>$/', $tok, $m)) {
            $flags |= FieldFlags::NUMERIC | FieldFlags::SIGNED;
            return [FieldType::Display, (int)$m[1], 0, (int)$m[1], $flags];
        }

        // decimal<n,m>  →  Display numeric unsigned with decimals
        if (preg_match('/^decimal<(\d+),(\d+)>$/', $tok, $m)) {
            $flags |= FieldFlags::NUMERIC;
            return [FieldType::Display, (int)$m[1], (int)$m[2], (int)$m[1] + (int)$m[2], $flags];
        }

        // sdecimal<n,m>  →  Display numeric signed with decimals
        if (preg_match('/^sdecimal<(\d+),(\d+)>$/', $tok, $m)) {
            $flags |= FieldFlags::NUMERIC | FieldFlags::SIGNED;
            return [FieldType::Display, (int)$m[1], (int)$m[2], (int)$m[1] + (int)$m[2], $flags];
        }

        // float32 / float64
        if ($tok === 'float32') {
            return [FieldType::Float32, 0, 0, 4, $flags];
        }
        if ($tok === 'float64') {
            return [FieldType::Float64, 0, 0, 8, $flags];
        }

        // edited<"mask">  →  Display, length = count of non-special chars in mask
        if (preg_match('/^edited$/', $tok)) {
            $mask = '';
            if ($this->peek() === '<') {
                $this->advance();
                $mask = trim($this->advance(), '"\'');
                $this->consume('>');
            }
            $len = $this->editedLength($mask);
            return [FieldType::Display, 0, 0, $len, $flags, $mask];
        }

        // pointer / procedure_pointer
        if ($tok === 'pointer' || $tok === 'procedure_pointer') {
            return [FieldType::Native, 0, 0, PHP_INT_SIZE, $flags];
        }

        // fallback — treat as 1-byte display
        return [FieldType::Display, 0, 0, 1, $flags];
    }

    //------------------------------------------------------------
    // Count the output bytes of an edited picture mask
    // Each mask character represents one output byte
    // except V (implied decimal, no byte)
    //------------------------------------------------------------
    private function editedLength(string $mask): int
    {
        // remove CR and DB suffixes (2 chars = 2 bytes, handled below)
        $len = 0;
        $i   = 0;
        $upper = strtoupper($mask);
        while ($i < strlen($upper)) {
            if (substr($upper, $i, 2) === 'CR' || substr($upper, $i, 2) === 'DB') {
                $len += 2;
                $i   += 2;
            } elseif ($upper[$i] === 'V') {
                $i++; // V = implied decimal, no byte
            } else {
                $len++;
                $i++;
            }
        }
        return max(1, $len);
    }

    //------------------------------------------------------------
    // Parse occurs(n [, depending: $f] [, index: $i] [, asc: $k])
    // Returns [min, max, dependingOnField]
    //------------------------------------------------------------
    private function parseOccursClause(): array
    {
        $this->consume('occurs');
        $this->consume('(');

        $occursMin   = 1;
        $occursMax   = 1;
        $dependingOn = null;

        // size: either n  or  n..m
        $first = $this->advance();
        if ($this->peek() === '..') {
            $this->advance(); // consume '..'
            $second    = $this->advance();
            $occursMin = (int)$first;
            $occursMax = (int)$second;
        } else {
            $occursMax = (int)$first;
        }

        // named arguments: depending:, index:, asc:, desc:
        while ($this->peek() === ',') {
            $this->advance(); // consume ','
            $argName = $this->advance(); // depending / index / asc / desc
            if ($this->peek() === ':') {
                $this->advance(); // consume ':'
                $argVal = $this->advance(); // $fieldName or $idxName
                if ($argName === 'depending') {
                    $dependingOn = ltrim($argVal, '$');
                }
                // index / asc / desc: recorded in layout metadata (future)
            }
        }

        $this->consume(')');
        return [$occursMin, $occursMax, $dependingOn];
    }

    //------------------------------------------------------------
    // Parse:  when $field == 'v' [| 'v2'] : bool $condName ;
    //         when $field in lo..hi : bool $condName ;
    //------------------------------------------------------------
    private function parseConditionName(): array
    {
        $this->consume('when');
        $this->consumeIdentifier(); // $fieldName — we already know it's the last field

        $op = $this->advance(); // == or 'in'

        $values = null;
        $range  = null;

        if ($op === '==') {
            $values = [trim($this->advance(), "'\"")];
            while ($this->peek() === '|') {
                $this->advance();
                $values[] = trim($this->advance(), "'\"");
            }
        } elseif ($op === 'in') {
            $lo    = $this->advance();
            $this->consume('..');
            $hi    = $this->advance();
            $range = [(int)$lo, (int)$hi];
        }

        $this->consume(':');
        $this->consume('bool');
        $condName = ltrim($this->consumeIdentifier(), '$');
        if ($this->peek() === ';') $this->advance();

        return ['name' => $condName, 'values' => $values, 'range' => $range];
    }

    //------------------------------------------------------------
    // Parse:  standalone type [modifiers] $name [= value] ;
    //------------------------------------------------------------
    private function parseStandalone(): PHoPolField
    {
        $this->consume('standalone');
        return $this->parseTypeAndName(null, 0);
    }
}
