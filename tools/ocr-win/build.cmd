@echo off
rem Build the Windows exe (PyInstaller). Double-click this file.
rem ASCII-only on purpose: cmd.exe codepage varies, keep messages readable everywhere.
rem PyInstaller arguments live in build_exe.py (shared with build.ps1), and the OCR
rem dependency list is derived from paddlex metadata - never hardcode it here.
setlocal
cd /d "%~dp0"

set "PYEXE=.venv\Scripts\python.exe"
if exist "%PYEXE%" goto haveenv

set "PY="
where py >nul 2>nul
if not errorlevel 1 set "PY=py -3"
if not defined PY set "PY=python"
echo [1/5] Creating virtual env .venv ...
%PY% -m venv .venv
if errorlevel 1 goto failed

:haveenv
if not exist "%PYEXE%" (
    echo [ERROR] venv not created: %PYEXE% missing.
    echo         Install Python 3.10+ and check "Add python.exe to PATH", then reopen the terminal.
    goto failed
)

echo [2/5] Upgrading pip ...
"%PYEXE%" -m pip install -U pip
if errorlevel 1 goto failed

echo [3/5] Installing dependencies ^(first run: 1-2 GB, be patient^) ...
"%PYEXE%" -m pip install -r requirements.txt -r requirements-build.txt
if errorlevel 1 goto failed

echo [4/5] Packaging with PyInstaller ^(OCR deps derived from paddlex metadata^) ...
"%PYEXE%" build_exe.py
if errorlevel 1 goto failed

echo [5/5] Smoke test: dist\ocr-win\ocr-win.exe self-check ...
"dist\ocr-win\ocr-win.exe" self-check
if errorlevel 1 goto smokefailed

echo.
echo Done: %CD%\dist\ocr-win\ocr-win.exe
echo Next: generate an API Key at /dashboard, then run:
echo       dist\ocr-win\ocr-win.exe self-check --server ^<site-url^> --token ^<key^>
pause
exit /b 0

:smokefailed
echo.
echo [FAILED] The packaged exe failed self-check - it cannot initialize the OCR pipeline.
echo          PyInstaller missed runtime dependencies. Do NOT ship dist\ocr-win.
echo          Scroll up for the missing package names, screenshot the whole output.
pause
exit /b 1

:failed
echo.
echo [FAILED] The step above returned a non-zero exit code. Screenshot the whole output.
pause
exit /b 1
