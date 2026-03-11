#!/usr/bin/env bash
set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")"/../../scripts && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/config.sh"

log_header "Composer Normalize"

if [ -d vendor/ergebnis/composer-normalize ]; then
    echo "→ Checking composer.json normalization…"
    composer normalize
else
    echo ""
    echo "❌ ergebnis/composer-normalize is not installed"
    echo ""
    echo "This package requires composer-normalize for composer.json validation."
    echo ""
    echo "Install with:"
    echo "  composer require --dev ergebnis/composer-normalize"
    echo ""
    exit 1
fi
