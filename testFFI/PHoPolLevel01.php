<?php

declare(strict_types=1);

namespace PHoPol;

//================================================================
// PHoPolLevel01 — a level(01) level: anchors the FFI buffer.
//
// Extends PHoPolLevel, adding:
//   - FFI buffer allocation (or adoption of an external buffer)
//   - attach()            — load raw bytes from file / network
//   - registerRedefines() — wire REDEFINES buffer sharing
//   - rawBytes()          — export buffer as a PHP string
//================================================================
final class PHoPolLevel01 extends PHoPolLevel
{
    private bool $ownsBuffer;

    /** @var PHoPolLevel01[] */
    private array $redefiningLevels = [];

    private static ?\FFI $ffi = null;

    public function __construct(
        PHoPolLayout $layout,
        ?\FFI\CData  $externalBuffer = null,
    ) {
        if ($externalBuffer !== null) {
            $buffer           = $externalBuffer;
            $this->ownsBuffer = false;
        } else {
            $buffer           = self::ffi()->new("uint8_t[{$layout->totalLength}]", true);
            $this->ownsBuffer = true;
            \FFI::memset($buffer, 0x20, $layout->totalLength);
        }
        parent::__construct($layout, $buffer, 0);

        // Apply VALUE clause initialisers only for fresh allocations.
        // attach() / externalBuffer adopts existing data as-is.
        if ($externalBuffer === null) {
            $this->applyInitialValues();
        }
    }

    private function applyInitialValues(): void
    {
        foreach ($this->layout->allFields() as $field) {
            if ($field->initialValue === null) continue;

            $cnt  = $field->isOccurs() ? $field->occursMax : 1;
            $step = $field->isOccurs() ? $field->entrySize : 0;

            if ($field->initialIsFill) {
                $fillByte = ord($field->initialValue[0]);
                for ($ci = 0; $ci < $cnt; $ci++) {
                    \FFI::memset(
                        $this->buffer + $field->offset + $ci * $step,
                        $fillByte,
                        $field->length
                    );
                }
            } else {
                for ($ci = 0; $ci < $cnt; $ci++) {
                    $this->encode($field, $field->offset + $ci * $step, $field->initialValue);
                }
            }
        }
    }

    private static function ffi(): \FFI
    {
        return self::$ffi ??= \FFI::cdef('');
    }

    //------------------------------------------------------------
    // attach() — copy raw bytes into the buffer (e.g. from fread)
    // One memcpy on ingest; all subsequent field accesses are zero-copy.
    //------------------------------------------------------------
    public function attach(string $rawBytes): void
    {
        $len = min(strlen($rawBytes), $this->layout->totalLength);
        \FFI::memcpy($this->buffer, $rawBytes, $len);
        foreach ($this->redefiningLevels as $level) {
            $level->attachBuffer($this->buffer);
        }
    }

    //------------------------------------------------------------
    // attachBuffer() — point this level at another level's buffer.
    // Called internally when a REDEFINES relationship is wired.
    //------------------------------------------------------------
    public function attachBuffer(\FFI\CData $buffer): void
    {
        $this->buffer     = $buffer;
        $this->ownsBuffer = false;
    }

    //------------------------------------------------------------
    // registerRedefines() — wire a redefining level01 to share our buffer.
    // Writing through either level modifies the same bytes.
    //------------------------------------------------------------
    public function registerRedefines(PHoPolLevel01 $other): void
    {
        $this->redefiningLevels[] = $other;
        $other->attachBuffer($this->buffer);
    }

    //------------------------------------------------------------
    // rawBytes() — export the full buffer as a PHP string (for file write)
    //------------------------------------------------------------
    public function rawBytes(): string
    {
        return \FFI::string($this->buffer, $this->layout->totalLength);
    }
}
