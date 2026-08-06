#!/bin/bash
set -e
cd /home/z/my-project/RCS_HRMS/RCS_ESS
echo "=== Working directory: $(pwd) ==="
echo "=== Installing xlsx from SheetJS CDN ==="
npm install https://cdn.sheetjs.com/xlsx-latest/xlsx-latest.tgz --save 2>&1
echo "=== Verifying xlsx in package.json ==="
grep xlsx package.json || echo "WARNING: xlsx not found in package.json"
echo "=== Done ==="