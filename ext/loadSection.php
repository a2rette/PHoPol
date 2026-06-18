<?php
declare(strict_types=1);

namespace PHoPol;

require_once __DIR__ . '/PHoPolParser.php';

/**
 * Parse a .phopol file, register all layouts with the extension,
 * allocate levels (wiring REDEFINES via attachTo()), and return them.
 *
 * @return array<string, \PHoPolLevel01>   layout name → level instance
 */
function loadSection(string $phopolFile): array
{
    if (!file_exists($phopolFile)) {
        throw new \RuntimeException("loadSection: file not found: $phopolFile");
    }

    $layouts = (new PHoPolParser())->parse(file_get_contents($phopolFile));

    foreach ($layouts as $layout) {
        phopol_register_layout(
            $layout['name'],
            $layout['totalLength'],
            $layout['fields'],
            $layout['conditions']          ?: null,
            $layout['decimalPointIsComma'] ?? false
        );
    }

    // First pass: allocate non-redefining levels
    $levels = [];
    foreach ($layouts as $name => $layout) {
        if ($layout['redefines'] === null) {
            $level = new \PHoPolLevel01($name);
            $level->allocate();
            $levels[$name] = $level;
        }
    }

    // Second pass: wire REDEFINES — overlay shares the base buffer
    foreach ($layouts as $name => $layout) {
        if ($layout['redefines'] !== null) {
            $baseName = $layout['redefines'];
            if (!isset($levels[$baseName])) {
                throw new \RuntimeException(
                    "loadSection: REDEFINES target '$baseName' not found " .
                    "(declare it before the redefining level)"
                );
            }
            $overlay = new \PHoPolLevel01($name);
            $overlay->attachTo($levels[$baseName]);
            $levels[$name] = $overlay;
        }
    }

    return $levels;
}
