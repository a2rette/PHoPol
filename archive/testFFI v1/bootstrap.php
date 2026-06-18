<?php

declare(strict_types=1);

namespace CobPhp;

//================================================================
// bootstrap.php
//
// Loads a .cobphp WSS file:
//   1. Parses it into CobPhpLayout objects
//   2. Allocates CobPhpRecord instances (each backed by FFI buffer)
//   3. Wires REDEFINES relationships (shared buffers, zero copy)
//   4. Returns a WssContext holding all records
//
// Usage in a program:
//   require 'cobphp/autoload.php';
//   $wss = CobPhp\bootstrap(__DIR__ . '/wss_simple.cobphp');
//================================================================

//----------------------------------------------------------------
// WssContext — the runtime $wss object
//----------------------------------------------------------------
final class WssContext
{
    /** @var array<string, CobPhpLevel01> */
    private array $records    = [];

    /** @var array<string, mixed> */
    private array $standalone = [];

    public function addRecord(string $name, CobPhpLevel01 $record): void
    {
        $this->records[$name] = $record;
    }

    public function getRecord(string $name): ?CobPhpLevel01
    {
        return $this->records[$name] ?? null;
    }

    public function addStandalone(string $name, mixed $value): void
    {
        $this->standalone[$name] = $value;
    }

    /** @return array<string, CobPhpLevel01> */
    public function allRecords(): array
    {
        return $this->records;
    }

    //------------------------------------------------------------
    // dumpLayouts() — print all layout descriptors (debugging)
    //------------------------------------------------------------
    public function dumpLayouts(): string
    {
        $out = '';
        foreach ($this->records as $record) {
            $out .= $record->layout->dump() . "\n";
        }
        return $out;
    }
}


//================================================================
// bootstrap() — main entry point
//================================================================
function bootstrap(string $cobphpFile): WssContext
{
    if (! file_exists($cobphpFile)) {
        throw new \RuntimeException("bootstrap: file not found: $cobphpFile");
    }

    $source  = file_get_contents($cobphpFile);
    $parser  = new CobPhpParser();
    $layouts = $parser->parse($source);

    $ctx = new WssContext();

    // first pass — allocate records that don't redefine anything
    foreach ($layouts as $name => $layout) {
        if (str_starts_with($name, '__standalone_')) {
            // standalone field — store initial value
            foreach ($layout->allFields() as $field) {
                $ctx->addStandalone($field->name, 0);
            }
            continue;
        }
        if ($layout->redefines === null) {
            $record = new CobPhpLevel01($layout);
            $ctx->addRecord($name, $record);
        }
    }

    // second pass — wire REDEFINES (share the base record's buffer)
    foreach ($layouts as $name => $layout) {
        if ($layout->redefines !== null) {
            $baseRecord = $ctx->getRecord($layout->redefines);
            if ($baseRecord === null) {
                throw new \RuntimeException(
                    "bootstrap: redefines target '{$layout->redefines}' not found " .
                    "(make sure it is declared before the redefining record)"
                );
            }
            $redefRecord = new CobPhpLevel01($layout);
            $baseRecord->registerRedefines($redefRecord);
            $ctx->addRecord($name, $redefRecord);
        }
    }

    return $ctx;
}
