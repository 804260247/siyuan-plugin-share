"""Windows 打包入口（PyInstaller 用；`python -m ocrwin` 亦可）。"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from ocrwin.cli import main  # noqa: E402

if __name__ == "__main__":
    sys.exit(main())
