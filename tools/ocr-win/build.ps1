# 打包 Windows exe（PyInstaller）
#   .\build.ps1            -> --onedir（默认，启动快、更稳）
#   .\build.ps1 -OneFile   -> 单文件 exe（启动慢一点）
#
# 本文件是 Windows 专用脚本，必须以「CRLF 行尾 + UTF-8 BOM」入库：
#   - Windows PowerShell 5.1 遇到纯 LF 文件时，行尾反引号续行不可靠（0029 之前的报错原因）；
#   - 无 BOM 时 PS 5.1 按系统代码页（中文 Windows 为 GBK）解码，中文注释全乱。
# .gitattributes 已按「原样保存」钉住（*.ps1 -text），勿改成 LF 或无 BOM。
# 另：本脚本已彻底不用反引号续行，参数一律走数组 splatting，行尾格式不再影响解析。
param(
    [switch]$OneFile
)

$ErrorActionPreference = "Stop"

if ($PSVersionTable.PSVersion.Major -lt 5) {
    throw "需要 PowerShell 5.1 或更高版本（当前 $($PSVersionTable.PSVersion)）。请用「Windows PowerShell」运行本脚本，或直接双击 build.cmd。"
}

# 原生 exe 的非 0 退出码不会触发 $ErrorActionPreference，必须每次手动检查，
# 否则 pip / PyInstaller 失败了脚本照样打印「打包完成」。
function Invoke-Native {
    param(
        [string]$Exe,
        [string[]]$Arguments
    )
    & $Exe @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "命令失败：$Exe $($Arguments -join ' ')（退出码 $LASTEXITCODE）"
    }
}

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $here

$venvPython = Join-Path $here ".venv\Scripts\python.exe"

if (-not (Test-Path $venvPython)) {
    if (Get-Command python -ErrorAction SilentlyContinue) {
        $bootExe = "python"
        $bootArgs = @()
    } elseif (Get-Command py -ErrorAction SilentlyContinue) {
        $bootExe = "py"
        $bootArgs = @("-3")
    } else {
        throw "找不到 python：请先安装 Python 3.10 或更高版本（安装时勾选 Add python.exe to PATH），装好后重开终端再试。"
    }
    Write-Host "创建虚拟环境 .venv（一次性）..."
    $venvArgs = $bootArgs + @("-m", "venv", ".venv")
    Invoke-Native -Exe $bootExe -Arguments $venvArgs
    if (-not (Test-Path $venvPython)) {
        throw "虚拟环境创建失败：$venvPython 不存在。"
    }
}

Write-Host "安装/更新打包依赖（首次约 1-2 GB，耗时较长，请勿关闭窗口）..."
Invoke-Native -Exe $venvPython -Arguments @("-m", "pip", "install", "-U", "pip")
Invoke-Native -Exe $venvPython -Arguments @("-m", "pip", "install", "-r", "requirements.txt", "-r", "requirements-build.txt")

$mode = if ($OneFile) { "--onefile" } else { "--onedir" }

# paddlex / paddle 的模型加载走动态导入，需要显式收集；
# 参数写成数组逐个传，避免任何续行符（这是 0029 修掉解析错误的根本做法）。
$pyiArgs = @(
    "-m", "PyInstaller",
    $mode,
    "--name", "ocr-win",
    "--clean", "--noconfirm",
    "--collect-submodules", "paddlex",
    "--collect-data", "paddlex",
    "--collect-data", "paddlex.deploy",
    "--collect-submodules", "paddle",
    "--hidden-import", "onnxruntime",
    "--hidden-import", "PIL.Image",
    "run_ocr_win.py"
)

Write-Host "打包中（PyInstaller）..."
Invoke-Native -Exe $venvPython -Arguments $pyiArgs

$exe = if ($OneFile) { Join-Path $here "dist\ocr-win.exe" } else { Join-Path $here "dist\ocr-win\ocr-win.exe" }
if (-not (Test-Path $exe)) {
    throw "打包命令返回成功但产物不存在：$exe"
}

Write-Host ""
Write-Host "打包完成：$exe"
Write-Host ('验证："{0}" self-check' -f $exe)
