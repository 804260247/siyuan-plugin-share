# 打包 Windows exe（PyInstaller）
#   .\build.ps1            -> --onedir（默认，启动快、更稳）
#   .\build.ps1 -OneFile   -> 单文件 exe（启动慢一点）
param(
    [switch]$OneFile
)

$ErrorActionPreference = "Stop"
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $here

if (-not (Test-Path ".\.venv")) {
    python -m venv .venv
}
& ".\.venv\Scripts\python.exe" -m pip install -U pip
& ".\.venv\Scripts\python.exe" -m pip install -r requirements.txt -r requirements-build.txt

$mode = if ($OneFile) { "--onefile" } else { "--onedir" }

# paddlex / paddle 的模型加载走动态导入，需要显式收集
& ".\.venv\Scripts\python.exe" -m PyInstaller $mode `
    --name ocr-win `
    --clean --noconfirm `
    --collect-submodules paddlex `
    --collect-data paddlex `
    --collect-data paddlex.deploy `
    --collect-submodules paddle `
    --hidden-import onnxruntime `
    --hidden-import PIL.Image `
    run_ocr_win.py

Write-Host ""
Write-Host "打包完成：$here\dist\ocr-win$(if ($OneFile) { '.exe' } else { '\ocr-win.exe' })"
Write-Host "验证：.\dist\ocr-win\ocr-win.exe self-check"
