#!/usr/bin/env bash

set -euo pipefail

echo "🚀 Publishing to Packagist…"

PACKAGIST_TOKEN="${PACKAGIST_TOKEN:-}"
PACKAGIST_USERNAME="${PACKAGIST_USERNAME:-}"
CI_COMMIT_TAG="${CI_COMMIT_TAG:-}"
CI_PROJECT_PATH="${CI_PROJECT_PATH:-}"

# Verify credentials are configured
if [[ -z "$PACKAGIST_TOKEN" || -z "$PACKAGIST_USERNAME" ]]; then
    echo "❌ PACKAGIST_TOKEN or PACKAGIST_USERNAME not set"
    echo "Configure these variables in:"
    echo "  Settings > CI/CD > Variables"
    echo ""
    echo "Required variables:"
    echo "  - PACKAGIST_USERNAME: Your Packagist username"
    echo "  - PACKAGIST_TOKEN: Your Packagist Safe API Token"
    exit 1
fi

# Extract package name from composer.json
PACKAGE_NAME=$(jq -r '.name' composer.json)

echo "→ Package: $PACKAGE_NAME"
echo "→ Tag: $CI_COMMIT_TAG"
echo "→ Username: $PACKAGIST_USERNAME"
echo ""

echo "🔄 Triggering Packagist update…"
echo ""

# Call Packagist API
HTTP_CODE=$(curl --silent \
    --output response.json \
    --write-out "%{http_code}" \
    -X POST \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer ${PACKAGIST_USERNAME}:${PACKAGIST_TOKEN}" \
    "https://packagist.org/api/update-package" \
    -d "{\"repository\":\"https://gitlab.com/${CI_PROJECT_PATH}.git\"}")

echo "→ HTTP Response Code: $HTTP_CODE"
echo ""

# Process response
if [ "$HTTP_CODE" != "200" ] && [ "$HTTP_CODE" != "202" ]; then
    exit 1
fi
