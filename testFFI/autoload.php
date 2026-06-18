<?php

//================================================================
// autoload.php — simple PSR-4 autoloader for the PHoPol namespace
// Include this once at program entry.
//================================================================

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'PHoPol\\'))
		$file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 7)) . '.php';
	else
		$file = __DIR__ . '/PHoPol' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    } else {
		echo 'Autoload - File not found :' . $file . "\r\n";
		debug_print_backtrace();
		exit (1);
	}
});

// load files that define global functions and constants
require_once __DIR__ . '/PHoPolRuntime.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/PHoPolField.php';			// car on utilise l'enum FieldType avant la classe PHoPolFieldType et que l'autoload ne marche pas dessus