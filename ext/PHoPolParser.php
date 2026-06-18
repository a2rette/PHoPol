<?php
declare(strict_types=1);

namespace PHoPol;

//================================================================
// PHoPolParser — extension edition
//
// Parses .phopol source into plain PHP arrays that can be passed
// directly to phopol_register_layout().  No PHoPolField / PHoPolLayout
// objects — the output arrays ARE the C struct descriptors.
//
// parse() return format:
//   array<string, [
//     'name'                => string,
//     'totalLength'         => int,
//     'redefines'           => ?string,
//     'decimalPointIsComma' => bool,
//     'fields'              => [ field-array, ... ],
//     'conditions'          => [ cond-array, ... ],
//   ]>
//
// field-array keys:
//   name, offset, length, type (T_* int), digits, decimals,
//   flags (F_* bitmask), occursMax, entrySize, dependingOn,
//   editMask, initialValue, initialIsFill
//
// cond-array keys:
//   name, field (source field name), values (?string[]), range (?[lo,hi])
//================================================================
final class PHoPolParser
{
    // Type integers — MUST match PHOPOL_TYPE_* in phopol_layout.h
    private const T_DISPLAY    = 0;
    private const T_BINARY     = 1;
    private const T_PACKED     = 2;
    private const T_FLOAT32    = 3;
    private const T_FLOAT64    = 4;
    private const T_NATIVE     = 5;
    private const T_PHP_LONG   = 6;
    private const T_PHP_DOUBLE = 7;
    private const T_PHP_STRING = 8;

    // Flag bitmask — MUST match PHOPOL_FLAG_* in phopol_layout.h
    // No F_NUMERIC: the extension codec uses digits > 0 for numeric detection.
    private const F_SIGNED          = 1;   // 1 << 0
    private const F_SIGN_SEPARATE   = 2;   // 1 << 1
    private const F_JUSTIFIED_RIGHT = 4;   // 1 << 2
    private const F_BLANK_ZERO      = 8;   // 1 << 3

    /** @var array<string, array> */
    private array $layouts = [];
    private array $tokens  = [];
    private int   $pos     = 0;
    private bool  $decimalPointIsComma = false;

    // ----------------------------------------------------------------
    // Public API
    // ----------------------------------------------------------------

    /** @return array<string, array> */
    public function parse(string $source): array
    {
        $this->layouts             = [];
        $this->tokens              = $this->tokenize($source);
        $this->pos                 = 0;
        $this->decimalPointIsComma = false;

        while (!$this->atEnd()) {
            if ($this->peek() === 'namespace') {
                $this->consume('namespace');
                $this->consumeIdentifier();
                $this->consume('{');
                $this->parseNamespaceBody();
                $this->consume('}');
            } else {
                $this->advance();
            }
        }

        return $this->layouts;
    }

    // ----------------------------------------------------------------
    // Tokenizer
    // ----------------------------------------------------------------

    /** @return string[] */
    private function tokenize(string $source): array
    {
        $source = preg_replace('/\/\/[^\n]*/', '', $source);
        $source = preg_replace('/\/\*.*?\*\//s', '', $source);

        preg_match_all(
            '/
                \'[^\']*\'                      # single-quoted string
              | "[^"]*"                         # double-quoted string
              | [0-9]+\.[0-9]+                  # float literal
              | [0-9]+                          # integer literal
              | \.\.                            # range operator
              | ==?                             # == or =
              | edited                          # must precede general identifier
              | [{}();,:<>]                     # single-char punctuation
              | [+\-]                           # sign
              | \$[a-zA-Z_][a-zA-Z0-9_]*        # $variable
              | [a-zA-Z_][a-zA-Z0-9_<>,]*       # identifier (includes <n,m> type suffixes)
            /x',
            $source,
            $m
        );

        return $m[0];
    }

    // ----------------------------------------------------------------
    // Token stream helpers
    // ----------------------------------------------------------------

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
            throw new \RuntimeException('PHoPolParser: expected identifier, got EOF');
        }
        return $tok;
    }

    private function atEnd(): bool
    {
        return $this->pos >= count($this->tokens);
    }

    // ----------------------------------------------------------------
    // Namespace body
    // ----------------------------------------------------------------

    private function parseNamespaceBody(): void
    {
        while (!$this->atEnd() && $this->peek() !== '}') {
            $tok = $this->peek();

            if ($tok === 'level') {
                $layout = $this->parseLevel01();
                if ($layout !== null) {
                    $this->layouts[$layout['name']] = $layout;
                }
            } elseif ($tok === 'decimal_point') {
                $this->advance();
                $this->consume('is');
                $this->consume('comma');
                if ($this->peek() === ';') $this->advance();
                $this->decimalPointIsComma = true;
            } elseif ($tok === 'standalone') {
                $this->advance(); // consume 'standalone'
                $field = $this->parseTypeAndName(null, 0);
                unset($field['redefinesTarget']);
                $this->layouts[$field['name']] = [
                    'name'                => $field['name'],
                    'totalLength'         => $field['length'],
                    'redefines'           => null,
                    'decimalPointIsComma' => $this->decimalPointIsComma,
                    'fields'              => [$field],
                    'conditions'          => [],
                ];
            } elseif (in_array($tok, ['global', 'external', 'shared'])) {
                $this->advance();
                $layout = $this->parseLevel01();
                if ($layout !== null) {
                    $this->layouts[$layout['name']] = $layout;
                }
            } else {
                $this->advance();
            }
        }
    }

    // ----------------------------------------------------------------
    // level(01) $Name [redefines $Other] { ... }
    // ----------------------------------------------------------------

    private function parseLevel01(): ?array
    {
        $this->consume('level');
        $this->consume('(');
        $this->consumeIdentifier(); // level number
        $this->consume(')');

        $name = ltrim($this->consumeIdentifier(), '$');

        $redefines = null;
        if ($this->peek() === 'redefines') {
            $this->advance();
            $redefines = ltrim($this->consumeIdentifier(), '$');
        }

        $this->consume('{');
        $fields     = [];
        $conditions = [];
        $offset     = 0;
        $this->parseGroupBody($fields, $offset, $conditions);
        $this->consume('}');

        return [
            'name'                => $name,
            'totalLength'         => $offset,
            'redefines'           => $redefines,
            'decimalPointIsComma' => $this->decimalPointIsComma,
            'fields'              => $fields,
            'conditions'          => $conditions,
        ];
    }

    // ----------------------------------------------------------------
    // Group body  { field* }
    // $conditions accumulates 88-level entries at the layout level.
    // ----------------------------------------------------------------

    private function parseGroupBody(array &$fields, int &$offset, array &$conditions): void
    {
        // Maps field/group name → ['start' => int, 'size' => int] for REDEFINES lookups.
        $knownOffsets = [];

        while (!$this->atEnd() && $this->peek() !== '}') {
            $tok = $this->peek();

            if ($tok === 'level') {
                // nested group — may have redefines and/or occurs
                $this->advance();
                $this->consume('(');
                $this->consumeIdentifier(); // level number
                $this->consume(')');

                // Name is optional — anonymous groups have no $name
                $subName     = str_starts_with($this->peek(), '$')
                    ? ltrim($this->advance(), '$')
                    : null;
                $occursMax   = 1;
                $dependingOn = null;
                $redefTarget = null;

                // postfix: level(n) [$Name] redefines $Target { }
                if ($this->peek() === 'redefines') {
                    $this->advance();
                    $redefTarget = ltrim($this->consumeIdentifier(), '$');
                }

                if ($this->peek() === 'occurs') {
                    [, $occursMax, $dependingOn] = $this->parseOccursClause();
                }

                $this->consume('{');
                $subFields     = [];
                $subConditions = [];
                $subOffset     = 0;
                $this->parseGroupBody($subFields, $subOffset, $subConditions);
                $this->consume('}');

                $entrySize = $subOffset;

                if ($redefTarget !== null && isset($knownOffsets[$redefTarget])) {
                    $groupStart = $knownOffsets[$redefTarget]['start'];
                    $advance    = 0; // REDEFINES adds no new storage
                } else {
                    $groupStart = $offset;
                    $advance    = $entrySize * $occursMax;
                }

                foreach ($subFields as $sf) {
                    $fieldName = ($subName !== null && $occursMax > 1)
                        ? "{$subName}[*]_{$sf['name']}"
                        : $sf['name'];

                    $fields[] = [
                        'name'         => $fieldName,
                        'offset'       => $groupStart + $sf['offset'],
                        'length'       => $sf['length'],
                        'type'         => $sf['type'],
                        'digits'       => $sf['digits'],
                        'decimals'     => $sf['decimals'],
                        'flags'        => $sf['flags'],
                        'occursMax'    => $sf['occursMax'] > 1 ? $sf['occursMax'] : $occursMax,
                        'entrySize'    => $sf['occursMax'] > 1 ? $sf['entrySize'] : ($occursMax > 1 ? $entrySize : 0),
                        'dependingOn'  => $dependingOn,
                        'editMask'     => $sf['editMask'],
                        'initialValue' => $sf['initialValue'],
                        'initialIsFill'=> $sf['initialIsFill'],
                    ];
                }

                // bubble sub-conditions up, renaming their field reference
                foreach ($subConditions as $sc) {
                    $sc['field']  = ($subName !== null && $occursMax > 1)
                        ? "{$subName}[*]_{$sc['field']}"
                        : $sc['field'];
                    $conditions[] = $sc;
                }

                if ($subName !== null) {
                    $knownOffsets[$subName] = ['start' => $groupStart, 'size' => $entrySize * max(1, $occursMax)];
                }
                $offset += $advance;

            } elseif ($tok === 'when') {
                $cond = $this->parseConditionName();
                if (!empty($fields)) {
                    $entry = [
                        'name'  => $cond['name'],
                        'field' => $fields[count($fields) - 1]['name'],
                    ];
                    if ($cond['values'] !== null) {
                        $entry['values'] = $cond['values'];
                    } else {
                        $entry['range'] = $cond['range'];
                    }
                    $conditions[] = $entry;
                }

            } elseif ($tok === 'filler') {
                $this->advance();
                $field   = $this->parseTypeAndName('__filler_' . $offset, $offset);
                $offset += $field['length'];
                // filler not added to $fields — unreferenceable

            } elseif ($tok === 'alias') {
                while (!$this->atEnd() && $this->peek() !== ';') $this->advance();
                $this->advance();

            } else {
                $fieldStart  = $offset;
                $field       = $this->parseTypeAndName(null, $offset);
                $redefTarget = $field['redefinesTarget'];
                unset($field['redefinesTarget']);

                if ($redefTarget !== null && isset($knownOffsets[$redefTarget])) {
                    $field['offset'] = $knownOffsets[$redefTarget]['start'];
                    $knownOffsets[$field['name']] = ['start' => $field['offset'], 'size' => $field['length'] * max(1, $field['occursMax'])];
                    // No $offset advance — shares existing storage
                } else {
                    $offset += $field['length'] * max(1, $field['occursMax']);
                    $knownOffsets[$field['name']] = ['start' => $fieldStart, 'size' => $field['length'] * max(1, $field['occursMax'])];
                }
                $fields[] = $field;
            }
        }
    }

    // ----------------------------------------------------------------
    // Elementary field:  type [mods] $name [occurs(...)] [= value] ;
    // ----------------------------------------------------------------

    private function parseTypeAndName(?string $forcedName, int $baseOffset): array
    {
        if ($this->peek() === 'php') {
            $this->advance();
            $subType = $this->advance();
            [$type, $digits, $decimals, $length, $flags, $editMask] = match($subType) {
                'int'    => [self::T_PHP_LONG,   0, 0, 8, 0, ''],
                'float'  => [self::T_PHP_DOUBLE, 0, 0, 8, 0, ''],
                'string' => [self::T_PHP_STRING, 0, 0, 8, 0, ''],
                default  => throw new \RuntimeException(
                    "PHoPolParser: unknown php type '$subType'"),
            };
        } else {
            [$type, $digits, $decimals, $length, $flags, $editMask]
                = $this->resolveType($this->advance()) + [5 => ''];
        }

        while (in_array($this->peek(), ['binary','comp','packed','native',
                                         'sync','justify','blank_zero','signed_separate'])) {
            $mod = $this->advance();
            if ($mod === 'packed') {
                $type   = self::T_PACKED;
                $length = (int)ceil(($digits + $decimals + 1) / 2);
            } elseif (in_array($mod, ['binary', 'comp'])) {
                $type   = self::T_BINARY;
                $length = match(true) { $digits <= 4 => 2, $digits <= 9 => 4, default => 8 };
            } elseif ($mod === 'native') {
                $type   = self::T_NATIVE;
                $length = match(true) { $digits <= 4 => 2, $digits <= 9 => 4, default => 8 };
            } elseif ($mod === 'signed_separate') {
                $flags |= self::F_SIGN_SEPARATE;
                $length += 1;
            } elseif ($mod === 'justify') {
                $flags |= self::F_JUSTIFIED_RIGHT;
                if ($this->peek() === '(') { $this->advance(); $this->consumeIdentifier(); $this->consume(')'); }
            } elseif ($mod === 'blank_zero') {
                $flags |= self::F_BLANK_ZERO;
            } elseif ($mod === 'sync') {
                if ($this->peek() === '(') { $this->advance(); $this->consumeIdentifier(); $this->consume(')'); }
            }
        }

        $name = $forcedName ?? ltrim($this->consumeIdentifier(), '$');

        // postfix field REDEFINES: type $name redefines $target [occurs(...)] [= val] ;
        $redefinesTarget = null;
        if ($this->peek() === 'redefines') {
            $this->advance();
            $redefinesTarget = ltrim($this->consumeIdentifier(), '$');
        }

        $occursMax   = 1;
        $entrySize   = 0;
        $dependingOn = null;
        if ($this->peek() === 'occurs') {
            [, $occursMax, $dependingOn] = $this->parseOccursClause();
            $entrySize = $length;
        }

        $initialValue  = null;
        $initialIsFill = false;
        if ($this->peek() === '=') {
            $this->advance();
            $sign = '';
            if ($this->peek() === '-') { $sign = '-'; $this->advance(); }
            $raw = trim($this->advance(), "'\"");
            [$initialValue, $initialIsFill] = match (strtoupper($raw)) {
                'SPACE',      'SPACES'          => [' ',    true],
                'ZERO',       'ZEROS', 'ZEROES' => ['0',   false],
                'HIGH_VALUE', 'HIGH_VALUES'     => ["\xFF", true],
                'LOW_VALUE',  'LOW_VALUES'      => ["\x00", true],
                'QUOTE',      'QUOTES'          => ['"',   false],
                default                         => [$sign . $raw, false],
            };
        }

        if ($this->peek() === ';') $this->advance();

        // Leaf OCCURS: adopt groupName[*]_fieldName so the C extension treats it as an
        // OCCURS group (same convention as group-level OCCURS sub-fields).
        if ($occursMax > 1 && $forcedName === null) {
            $name = "{$name}[*]_{$name}";
        }

        return [
            'name'           => $name,
            'offset'         => $baseOffset,
            'length'         => $length,
            'type'           => $type,
            'digits'         => $digits,
            'decimals'       => $decimals,
            'flags'          => $flags,
            'occursMax'      => $occursMax,
            'entrySize'      => $entrySize,
            'dependingOn'    => $dependingOn,
            'editMask'       => $editMask,
            'initialValue'   => $initialValue,
            'initialIsFill'  => $initialIsFill,
            'redefinesTarget'=> $redefinesTarget,
        ];
    }

    // ----------------------------------------------------------------
    // Type token  →  [type, digits, decimals, length, flags, ?editMask]
    // ----------------------------------------------------------------

    private function resolveType(string $tok): array
    {
        $f = 0; // flags start at zero

        if (preg_match('/^string<(\d+)>$/', $tok, $m))
            return [self::T_DISPLAY, 0, 0, (int)$m[1], $f];

        if (preg_match('/^alpha<(\d+)>$/', $tok, $m))
            return [self::T_DISPLAY, 0, 0, (int)$m[1], $f];

        if (preg_match('/^uint<(\d+)>$/', $tok, $m))
            return [self::T_DISPLAY, (int)$m[1], 0, (int)$m[1], $f];

        if (preg_match('/^int<(\d+)>$/', $tok, $m))
            return [self::T_DISPLAY, (int)$m[1], 0, (int)$m[1], $f | self::F_SIGNED];

        if (preg_match('/^decimal<(\d+),(\d+)>$/', $tok, $m))
            return [self::T_DISPLAY, (int)$m[1], (int)$m[2], (int)$m[1] + (int)$m[2], $f];

        if (preg_match('/^sdecimal<(\d+),(\d+)>$/', $tok, $m))
            return [self::T_DISPLAY, (int)$m[1], (int)$m[2], (int)$m[1] + (int)$m[2], $f | self::F_SIGNED];

        if ($tok === 'float32') return [self::T_FLOAT32, 0, 0, 4, $f];
        if ($tok === 'float64') return [self::T_FLOAT64, 0, 0, 8, $f];

        if ($tok === 'edited') {
            $mask = '';
            if ($this->peek() === '<') {
                $this->advance();
                $mask = trim($this->advance(), '"\'');
                $this->consume('>');
            }
            return [self::T_DISPLAY, 0, 0, $this->editedLength($mask), $f, $mask];
        }

        if ($tok === 'pointer' || $tok === 'procedure_pointer')
            return [self::T_NATIVE, 0, 0, PHP_INT_SIZE, $f];

        return [self::T_DISPLAY, 0, 0, 1, $f]; // fallback
    }

    // ----------------------------------------------------------------
    // Count output bytes of an edited picture mask (V = implied decimal)
    // ----------------------------------------------------------------

    private function editedLength(string $mask): int
    {
        $len = 0;
        $i   = 0;
        $up  = strtoupper($mask);
        while ($i < strlen($up)) {
            if (substr($up, $i, 2) === 'CR' || substr($up, $i, 2) === 'DB') {
                $len += 2; $i += 2;
            } elseif ($up[$i] === 'V') {
                $i++;
            } else {
                $len++; $i++;
            }
        }
        return max(1, $len);
    }

    // ----------------------------------------------------------------
    // occurs(n [..m] [, depending: $f] [, index: $i])
    // Returns [min, max, dependingOnField]
    // ----------------------------------------------------------------

    private function parseOccursClause(): array
    {
        $this->consume('occurs');
        $this->consume('(');

        $occursMin   = 1;
        $occursMax   = 1;
        $dependingOn = null;

        $first = $this->advance();
        if ($this->peek() === '..') {
            $this->advance();
            $occursMin = (int)$first;
            $occursMax = (int)$this->advance();
        } else {
            $occursMax = (int)$first;
        }

        while ($this->peek() === ',') {
            $this->advance();
            $argName = $this->advance();
            if ($this->peek() === ':') {
                $this->advance();
                $argVal = $this->advance();
                if ($argName === 'depending') {
                    $dependingOn = ltrim($argVal, '$');
                }
            }
        }

        $this->consume(')');
        return [$occursMin, $occursMax, $dependingOn];
    }

    // ----------------------------------------------------------------
    // when $field == 'v' [| 'v2'] : bool $condName ;
    // when $field in lo..hi       : bool $condName ;
    // ----------------------------------------------------------------

    private function parseConditionName(): array
    {
        $this->consume('when');
        $this->consumeIdentifier(); // $fieldName

        $op     = $this->advance();
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
}
