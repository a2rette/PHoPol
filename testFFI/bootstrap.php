<?php

declare(strict_types=1);

namespace PHoPol;

//================================================================
// bootstrap.php
//
// Loads a .phopol WSS file:
//   1. Parses it into PHoPolLayout objects
//   2. Allocates PHoPolLevel01 instances (each backed by FFI buffer)
//   3. Wires REDEFINES relationships (shared buffers, zero copy)
//   4. Returns a WssContext holding all levels
//
// Usage in a program:
//   require 'phopol/autoload.php';
//   $wss = PHoPol\bootstrap(__DIR__ . '/wss_simple.phopol');
//================================================================

//----------------------------------------------------------------
// WssContext — the runtime $wss object
//----------------------------------------------------------------
final class WssContext
{
    /** @var array<string, PHoPolLevel01> */
    private array $levels = [];

    /** @var array<string, mixed> */
    private array $standalone = [];

    public function addLevel(string $name, PHoPolLevel01 $level): void
    {
        $this->levels[$name] = $level;
    }

    public function getLevel(string $name): ?PHoPolLevel01
    {
        return $this->levels[$name] ?? null;
    }

    public function addStandalone(string $name, mixed $value): void
    {
        $this->standalone[$name] = $value;
    }

    /** @return array<string, PHoPolLevel01> */
    public function allLevels(): array
    {
        return $this->levels;
    }

    //------------------------------------------------------------
    // dumpLayouts() — print all layout descriptors (debugging)
    //------------------------------------------------------------
    public function dumpLayouts(): string
    {
        $out = '';
        foreach ($this->levels as $level) {
            $out .= $level->layout->dump() . "\n";
        }
        return $out;
    }
}


//================================================================
// bootstrap() — main entry point
//================================================================
function bootstrap(string $phopolFile): WssContext
{
    if (! file_exists($phopolFile)) {
        throw new \RuntimeException("bootstrap: file not found: $phopolFile");
    }

    $source  = file_get_contents($phopolFile);
    $parser  = new PHoPolParser();
    $layouts = $parser->parse($source);

    $ctx = new WssContext();

    // first pass — allocate levels that don't redefine anything
    foreach ($layouts as $name => $layout) {
        if (str_starts_with($name, '__standalone_')) {
            // standalone field — store initial value
            foreach ($layout->allFields() as $field) {
                $ctx->addStandalone($field->name, 0);
            }
            continue;
        }
        if ($layout->redefines === null) {
            $level = new PHoPolLevel01($layout);
            $ctx->addLevel($name, $level);
        }
    }

    // second pass — wire REDEFINES (share the base level's buffer)
    foreach ($layouts as $name => $layout) {
        if ($layout->redefines !== null) {
            $baseLevel = $ctx->getLevel($layout->redefines);
            if ($baseLevel === null) {
                throw new \RuntimeException(
                    "bootstrap: redefines target '{$layout->redefines}' not found " .
                    "(make sure it is declared before the redefining level)"
                );
            }
            $redefLevel = new PHoPolLevel01($layout);
            $baseLevel->registerRedefines($redefLevel);
            $ctx->addLevel($name, $redefLevel);
        }
    }

    return $ctx;
}
