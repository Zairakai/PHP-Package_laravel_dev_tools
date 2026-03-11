#!/usr/bin/env bash
#
# Generate release notes from Git commits following git-rules conventions
# Usage: bash .gitlab/scripts/generate-release-notes.sh [to_tag] [from_tag]
#
set -euo pipefail

TO_TAG="${2:-$(git describe --tags --abbrev=0 HEAD)}"
FROM_TAG="${1:-$(git tag --sort=-v:refname | grep -A1 "^${TO_TAG}$" | tail -n1)}"

# Output files
RELEASE_NOTES="release_notes.md"
CHANGELOG_ENTRY="changelog_entry.md"

echo "━━━━━━━━━━━━━━━━"
echo "📝 Generating Release Notes"
echo "━━━━━━━━━━━━━━━━"
echo ""
echo "→ From: ${FROM_TAG}"
echo "→ To: ${TO_TAG}"
echo "→ Output: ${RELEASE_NOTES}"
echo ""

# Function to write to both stdout and file
output() {
    echo "$@" | tee -a "${RELEASE_NOTES}"
}

# Initialize files
touch "${RELEASE_NOTES}" "${CHANGELOG_ENTRY}"
: > "${RELEASE_NOTES}"
: > "${CHANGELOG_ENTRY}"

# Get commits between tags (exclude merge commits)
COMMITS=$(git log "${FROM_TAG}..${TO_TAG}" --pretty=format:"%s" --no-merges)

if [ -z "${COMMITS}" ]; then
    echo "⚠️  No commits found between ${FROM_TAG} and ${TO_TAG}"
    exit 0
fi

# Categorize commits by type (following git-rules)
FEATURES=$(echo "${COMMITS}" | grep "^feat" || true)
FIXES=$(echo "${COMMITS}" | grep "^fix" || true)
HOTFIXES=$(echo "${COMMITS}" | grep "^hotfix" || true)
DOCS=$(echo "${COMMITS}" | grep "^docs" || true)
STYLE=$(echo "${COMMITS}" | grep "^style" || true)
REFACTOR=$(echo "${COMMITS}" | grep "^refactor" || true)
PERF=$(echo "${COMMITS}" | grep "^perf" || true)
TESTS=$(echo "${COMMITS}" | grep "^test" || true)
BUILD=$(echo "${COMMITS}" | grep "^build" || true)
CI=$(echo "${COMMITS}" | grep "^ci" || true)
CHORE=$(echo "${COMMITS}" | grep "^chore" || true)

# Detect breaking changes
BREAKING_CHANGES=$(git log "${FROM_TAG}..${TO_TAG}" --pretty=format:"%b" --no-merges | grep -A 10 "^BREAKING CHANGE:" || true)

# Other commits (not following convention)
OTHER=$(echo "${COMMITS}" | grep -vE "^(feat|fix|hotfix|docs|style|refactor|perf|test|build|ci|chore)" || true)

# Function to format commit message
format_commit() {
    local commit="$1"
    local type_pattern="$2"

    # Remove type prefix and optional scope: "feat(scope): " or "feat: "
    MESSAGE=$(echo "${commit}" | sed -E "s/^${type_pattern}(\([^)]*\))?: //")

    # Extract ticket ID if present
    TICKET=$(echo "${MESSAGE}" | grep -oE "#[0-9]+" | head -n1 || echo "")

    if [ -n "${TICKET}" ]; then
        # Remove ticket ID from message for cleaner output
        MESSAGE=$(echo "${MESSAGE}" | sed "s/${TICKET} //")
        echo "- ${MESSAGE} (${TICKET})"
    else
        echo "- ${MESSAGE}"
    fi
}

# Generate release notes
output "## What's Changed"
output ""

# Breaking Changes (highest priority)
if [ -n "${BREAKING_CHANGES}" ]; then
    output "### ⚠️ BREAKING CHANGES"
    output ""
    echo "${BREAKING_CHANGES}" | sed '/^$/d' | sed 's/^BREAKING CHANGE://' | while read -r line; do
        if [ -n "${line}" ]; then
            output "- ${line}"
        fi
    done
    output ""
fi

# Features
if [ -n "${FEATURES}" ]; then
    output "### ✨ Features"
    output ""
    echo "${FEATURES}" | while read -r commit; do
        format_commit "${commit}" "feat" | tee -a "${RELEASE_NOTES}"
    done
    output ""
fi

# Bug Fixes
if [ -n "${FIXES}" ]; then
    output "### 🐛 Bug Fixes"
    output ""
    echo "${FIXES}" | while read -r commit; do
        format_commit "${commit}" "fix" | tee -a "${RELEASE_NOTES}"
    done
    output ""
fi

# Hotfixes
if [ -n "${HOTFIXES}" ]; then
    output "### 🚨 Hotfixes"
    output ""
    echo "${HOTFIXES}" | while read -r commit; do
        format_commit "${commit}" "hotfix" | tee -a "${RELEASE_NOTES}"
    done
    output ""
fi

# Performance Improvements
if [ -n "${PERF}" ]; then
    output "### ⚡ Performance"
    output ""
    echo "${PERF}" | while read -r commit; do
        format_commit "${commit}" "perf" | tee -a "${RELEASE_NOTES}"
    done
    output ""
fi

# Refactoring
if [ -n "${REFACTOR}" ]; then
    output "### ♻️ Refactoring"
    output ""
    echo "${REFACTOR}" | while read -r commit; do
        format_commit "${commit}" "refactor" | tee -a "${RELEASE_NOTES}"
    done
    output ""
fi

# Documentation
if [ -n "${DOCS}" ]; then
    output "### 📝 Documentation"
    output ""
    echo "${DOCS}" | while read -r commit; do
        format_commit "${commit}" "docs" | tee -a "${RELEASE_NOTES}"
    done
    output ""
fi

# Tests
if [ -n "${TESTS}" ]; then
    output "### 🧪 Tests"
    output ""
    echo "${TESTS}" | while read -r commit; do
        format_commit "${commit}" "test" | tee -a "${RELEASE_NOTES}"
    done
    output ""
fi

# Build System
if [ -n "${BUILD}" ]; then
    output "### 🏗️ Build System"
    output ""
    echo "${BUILD}" | while read -r commit; do
        format_commit "${commit}" "build" | tee -a "${RELEASE_NOTES}"
    done
    output ""
fi

# CI/CD
if [ -n "${CI}" ]; then
    output "### 👷 CI/CD"
    output ""
    echo "${CI}" | while read -r commit; do
        format_commit "${commit}" "ci" | tee -a "${RELEASE_NOTES}"
    done
    output ""
fi

# Code Style
if [ -n "${STYLE}" ]; then
    output "### 💄 Code Style"
    output ""
    echo "${STYLE}" | while read -r commit; do
        format_commit "${commit}" "style" | tee -a "${RELEASE_NOTES}"
    done
    output ""
fi

# Maintenance
if [ -n "${CHORE}" ]; then
    output "### 🔧 Maintenance"
    output ""
    echo "${CHORE}" | while read -r commit; do
        format_commit "${commit}" "chore" | tee -a "${RELEASE_NOTES}"
    done
    output ""
fi

# Other commits (not following convention)
if [ -n "${OTHER}" ]; then
    output "### 📦 Other Changes"
    output ""
    echo "${OTHER}" | while read -r commit; do
        output "- ${commit}"
    done
    output ""
fi

# Collect all referenced issues
output "### 🎫 Issues"
output ""
ISSUES=$(git log "${FROM_TAG}..${TO_TAG}" --pretty=format:"%b" --no-merges | \
    grep -oE "(Closes|Fixes|Resolves) #[0-9]+(, #[0-9]+)*" | \
    grep -oE "#[0-9]+" | sort -u || true)

if [ -n "${ISSUES}" ]; then
    output "This release closes the following issues:"
    output ""
    echo "${ISSUES}" | while read -r issue; do
        output "- ${issue}"
    done
else
    output "No issues referenced in commits."
fi

output ""

# Footer
output "---"
output ""
output "**Full Changelog**: ${FROM_TAG}...${TO_TAG}"

# Create changelog entry (same content but without header)
cp "${RELEASE_NOTES}" "${CHANGELOG_ENTRY}"

echo ""
echo "✅ Release notes generated:"
echo "   - ${RELEASE_NOTES}"
echo "   - ${CHANGELOG_ENTRY}"
