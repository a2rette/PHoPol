<?php

declare(strict_types=1);

namespace CobPhp;

//================================================================
// CobPhpLevel01 — a level(01) record: anchors the FFI buffer.
//
// Extends CobPhpLevel, adding:
//   - FFI buffer allocation (or adoption of an external buffer)
//   - attach()            — load raw bytes from file / network
//   - registerRedefines() — wire REDEFINES buffer sharing
//   - rawBytes()          — export buffer as a PHP string
//================================================================
final class CobPhpLevel01 extends CobPhpLevel
{
    private bool $ownsBuffer;

    /** @var CobPhpLevel01[] */
    private array $redefiningLevels = [];

    private static ?\FFI $ffi = null;

    public function __construct(
        CobPhpLayout $layout,
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
    // attachBuffer() — point this level at another record's buffer.
    // Called internally when a REDEFINES relationship is wired.
    //------------------------------------------------------------
    public function attachBuffer(\FFI\CData $buffer): void
    {
        $this->buffer     = $buffer;
        $this->ownsBuffer = false;
    }

    //------------------------------------------------------------
    // registerRedefines() — wire a redefining level01 to share our buffer.
    // Writing through either record modifies the same bytes.
    //------------------------------------------------------------
    public function registerRedefines(CobPhpLevel01 $other): void
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
