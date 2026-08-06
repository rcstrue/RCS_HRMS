#!/bin/bash
set -e
cd /home/z/my-project/RCS_HRMS/RCS_ESS
echo "=== Running npm run build ==="
npm run build 2>&1
echo "=== Build exit code: $? ==="