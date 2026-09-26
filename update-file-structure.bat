@echo off
setlocal

cd /d "%~dp0"

if exist "file-structure.txt" del /q "file-structure.txt"

tree /F /A > "%TEMP%\guristas-file-structure.txt"

move /Y "%TEMP%\guristas-file-structure.txt" "file-structure.txt" >nul

echo Updated:
echo %~dp0file-structure.txt
pause