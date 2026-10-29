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

# PyInstaller 参数与 OCR 依赖收集都在 build_exe.py 里生成（与 build.cmd 共用同一份），
# 这里只负责建 venv / 装依赖 / 调用它 / 冒烟自检 —— 参数只有一处，两边不可能再不一致。
# 依赖清单从 paddlex 的包元数据派生（ocrwin/pydeps.py），不硬编码包名。
$buildArgs = @("build_exe.py")
if ($OneFile) { $buildArgs += "--onefile" }

Write-Host "打包中（PyInstaller，产物约 1.5-2 GB，请勿关闭窗口）..."
Invoke-Native -Exe $venvPython -Arguments $buildArgs

$exe = if ($OneFile) { Join-Path $here "dist\ocr-win.exe" } else { Join-Path $here "dist\ocr-win\ocr-win.exe" }
if (-not (Test-Path $exe)) {
    throw "打包命令返回成功但产物不存在：$exe"
}

# 冒烟断言（0031）：产物必须能真正初始化 OCR 流水线，否则本节以失败收场，
# 不许再出现「打包完成但产物跑不起来」。
Write-Host ""
Write-Host "冒烟自检：$exe self-check（会真初始化 OCR 流水线）"
Invoke-Native -Exe $exe -Arguments @("self-check")

Write-Host ""
Write-Host "打包完成，且冒烟自检通过：$exe"
Write-Host ('下一步："{0}" self-check --server <站点地址> --token <API Key>' -f $exe)
