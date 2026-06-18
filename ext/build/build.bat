@echo off
setlocal
cd /d "%~dp0"

:: -----------------------------------------------------------------------
:: Paths — adjust PHP_BASE if you upgrade PHP or move the install
:: -----------------------------------------------------------------------
set PHP_BASE=C:\wamp\bin\php\php8.3.14
set PHP_INC=%PHP_BASE%\include
set PHP_LIB=%PHP_BASE%\dev\php8ts.lib
set VCVARS=C:\Program Files (x86)\Microsoft Visual Studio\2022\BuildTools\VC\Auxiliary\Build\vcvars64.bat

:: SRC_DIR  = ext\          (one level up from this build\ folder)
:: OUT_DIR  = ext\build\    (this folder; %~dp0 always has a trailing \)
:: The "for" loop resolves the ".." so cl.exe gets a clean absolute path.
for %%I in ("%~dp0..") do set SRC_DIR=%%~fI
set OUT_DIR=%~dp0

:: -----------------------------------------------------------------------
:: Initialise the VS2022 x64 toolchain (v14.2 / VS2019 ABI)
:: Required once per session; harmless to repeat.
:: -----------------------------------------------------------------------
echo Initialising VS2022 with v142 toolset...
call "%VCVARS%" -vcvars_ver=14.29
if %ERRORLEVEL% neq 0 ( echo ERROR: VS environment failed & exit /b 1 )

:: -----------------------------------------------------------------------
:: Compile all .c translation units into a single DLL
::
::   /LD          build a DLL (not a standalone .exe)
::   /MD          link against the DLL C runtime (required by PHP)
::   /O2          full optimisation
::   /W3          warning level 3
::
::   /DPHP_WIN32 /DZEND_WIN32   Windows-specific PHP/Zend guards
::   /DZTS                      thread-safe build (matches php8ts.lib)
::   /DCOMPILE_DL_PHOPOL        marks this as a dynamically-loaded ext
::   /DZEND_ENABLE_STATIC_TSRMLS_CACHE=1  per-module TSRMLS cache
::   /DZEND_DEBUG=0             release mode (no Zend internal assertions)
::
::   /Fo"OUT_DIR\"  object files go to build\  (trailing \ = directory)
::                  NOTE: the extra \ before " prevents "path\" from being
::                  read as an escaped quote by the C runtime arg parser.
::
::   /NODEFAULTLIB:LIBCMT  avoid LIBCMT vs MSVCRT link conflict
:: -----------------------------------------------------------------------
echo.
echo Compiling phopol extension...
cl.exe /LD /MD /O2 /W3 ^
    /DPHP_WIN32 /DZEND_WIN32 /DZTS /DCOMPILE_DL_PHOPOL ^
    /DZEND_ENABLE_STATIC_TSRMLS_CACHE=1 /DZEND_DEBUG=0 ^
    /I"%PHP_INC%" /I"%PHP_INC%\main" /I"%PHP_INC%\Zend" /I"%PHP_INC%\TSRM" /I"%PHP_INC%\win32" ^
    /Fo"%OUT_DIR%\" ^
    "%SRC_DIR%\phopol.c" "%SRC_DIR%\phopol_codec.c" "%SRC_DIR%\phopol_level.c" "%SRC_DIR%\phopol_runtime.c" "%PHP_LIB%" ^
    /link /DLL /OUT:"%OUT_DIR%php_phopol.dll" /NODEFAULTLIB:LIBCMT ^
    /IMPLIB:"%OUT_DIR%phopol.lib"

if %ERRORLEVEL% neq 0 ( echo. & echo BUILD FAILED & exit /b 1 )

:: -----------------------------------------------------------------------
:: Deploy the dll in the PHP_BASE folder, and smoke-test
:: -----------------------------------------------------------------------
echo.
echo Build OK -- copying to %PHP_BASE%\ext\
copy /Y "%OUT_DIR%php_phopol.dll" "%PHP_BASE%\ext\"

echo.
echo Testing extension loads...
php.exe -d "extension=php_phopol.dll" -r "echo extension_loaded('phopol') ? 'phopol OK' : 'phopol FAILED'; echo PHP_EOL;"

endlocal
