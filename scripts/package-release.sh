#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ROOT_DIR="$(cd "${PLUGIN_DIR}/.." && pwd)"
VERSION="$(sed -n 's/^ \* Version: \(.*\)$/\1/p' "${PLUGIN_DIR}/vibepresto.php" | head -n 1)"
OUTPUT_DIR="${ROOT_DIR}/releases"
OUTPUT_ZIP="${OUTPUT_DIR}/vibepresto-${VERSION}.zip"

mkdir -p "${OUTPUT_DIR}"
rm -f "${OUTPUT_ZIP}"

cd "${ROOT_DIR}"

zip -r "${OUTPUT_ZIP}" vibepresto \
  -x '*/.git/*' \
  -x '*/.gitignore' \
  -x '*/.DS_Store' \
  -x '*/scripts/*'

echo "Created ${OUTPUT_ZIP}"
