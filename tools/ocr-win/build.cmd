@echo off
rem Build the Windows exe (PyInstaller). Double-click this file.
rem ASCII-only on purpose: cmd.exe codepage varies, keep messages readable everywhere.
setlocal
cd /d "%~dp0"

set "PYEXE=.venv\Scripts\python.exe"
if exist "%PYEXE%" goto haveenv

set "PY="
where py >nul 2>nul
if not errorlevel 1 set "PY=py -3"
if not defined PY set "PY=python"
echo [1/4] Creating virtual env .venv ...
%PY% -m venv .venv
if errorlevel 1 goto failed

:haveenv
if not exist "%PYEXE%" (
    echo [ERROR] venv not created: %PYEXE% missing.
    echo         Install Python 3.10+ and check "Add python.exe to PATH", then reopen the terminal.
    goto failed
)

echo [2/4] Upgrading pip ...
"%PYEXE%" -m pip install -U pip
if errorlevel 1 goto failed

echo [3/4] Installing dependencies ^(first run: 1-2 GB, be patient^) ...
"%PYEXE%" -m pip install -r requirements.txt -r requirements-build.txt
if errorlevel 1 goto failed

echo [4/4] Packaging with PyInstaller ...
"%PYEXE%" -m PyInstaller --onedir --name ocr-win --clean --noconfirm --collect-submodules paddlex --collect-data paddlex --collect-data paddlex.deploy --collect-submodules paddle --hidden-import onnxruntime --hidden-import PIL.Image run_ocr_win.py
if errorlevel 1 goto failed

echo.
echo Done: %CD%\dist\ocr-win\ocr-win.exe
echo Verify: dist\ocr-win\ocr-win.exe self-check
pause
exit /b 0

:failed
echo.
echo [FAILED] The step above returned a non-zero exit code. Screenshot the whole output.
pause
exit /b 1
