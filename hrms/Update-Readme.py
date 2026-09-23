"""
RCS HRMS - Auto update README
Location: hrms/update_readme.py
Single file version: only hrms/repo-map.html (no docs folder)
"""
import re, datetime
from pathlib import Path

# ROOT = repo root (one level up from hrms/)
ROOT = Path(__file__).parent.parent
README = ROOT / "README.md"
# Single HTML file - hrms/repo-map.html
MAP_FILE = Path(__file__).parent / "repo-map.html"

def get_structure():
    important = ["RCS_ESS/src", "api/ess", "hrms", "php_payroll", "whatsapp-server", "database", ".github/workflows"]
    lines = []
    for b in important:
        p = ROOT / b
        if p.exists():
            cnt = sum(1 for _ in p.rglob("*") if _.is_file())
            lines.append(f"- `{b}/` -> {cnt} files")
    return "\n".join(lines) if lines else "- repo scan"

def update_readme():
    if not README.exists():
        print(f"README not found at {README}")
        return
    content = README.read_text(encoding="utf-8")
    now = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=5, minutes=30))).strftime("%d %b %Y, %I:%M %p IST")

    banner = f"""<!-- ARCH-MAP-START -->
## 🗺️ Interactive Repo Map

> **Live Visual Map:** [Open Full Architecture Diagram](./hrms/repo-map.html)

[![View Architecture Map](https://img.shields.io/badge/View-Interactive%20Map-orange?style=for-the-badge&logo=github)](./hrms/repo-map.html)

_Last auto-updated: {now} by GitHub Action_

<details>
<summary>📁 Quick Structure Snapshot (auto-generated)</summary>

```
{get_structure()}
```

</details>

<!-- ARCH-MAP-END -->
"""

    if "<!-- ARCH-MAP-START -->" in content:
        content = re.sub(r"<!-- ARCH-MAP-START -->.*?<!-- ARCH-MAP-END -->", banner.strip(), content, flags=re.DOTALL)
    else:
        if "## Architecture" in content:
            content = content.replace("## Architecture", banner + "\n\n## Architecture")
        else:
            content += "\n\n" + banner

    README.write_text(content, encoding="utf-8")
    print(f"README.md updated at {README}")

    if MAP_FILE.exists():
        html = MAP_FILE.read_text(encoding="utf-8")
        html = re.sub(r"<!-- AUTO-UPDATED.*?-->", "", html)
        html = html.replace("</head>", f"<!-- AUTO-UPDATED {now} --></head>")
        MAP_FILE.write_text(html, encoding="utf-8")
        print(f"Updated timestamp in {MAP_FILE}")

if __name__ == "__main__":
    update_readme()