@echo off
setlocal
cd /d "%~dp0"

set "inventoryTemp=%TEMP%\guristas-source-%RANDOM%-%RANDOM%.txt"
git -c core.quotepath=false ls-files --cached --others --exclude-standard > "%inventoryTemp%"
if errorlevel 1 (
    del /q "%inventoryTemp%" 2>nul
    echo Could not generate source inventory.
    exit /b 1
)

move /Y "%inventoryTemp%" "file-structure.txt" >nul
if errorlevel 1 (
    echo Could not save source inventory.
    exit /b 1
)

echo Updated source inventory: %~dp0file-structure.txt