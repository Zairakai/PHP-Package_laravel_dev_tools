#!/usr/bin/env bash
#
# Generate the release notes of a tag from the commits since the previous tag,
# following the git-rules conventions.
# Usage: bash .gitlab/scripts/generate-release-notes.sh [from_tag] [to_tag]
#   from_tag  the previous tag (default: the version tag before to_tag, none
#             for a first release)
#   to_tag    the tag to describe (default: the tag of HEAD)
# Writes release_notes.md in the current directory, and never leaves it empty.
set -euo pipefail

# The CI clones with a small depth and fetches only the tag of the pipeline:
# without the history and the other tags the
# previous tag is unknown, and the range between the tag and itself is empty.
fetch_history() {
  if [ "$(git rev-parse --is-shallow-repository 2>/dev/null)" = "true" ]; then
    git fetch --quiet --unshallow --tags \
      || echo "⚠️  Could not fetch the history: the notes may be incomplete"
  else
    git fetch --quiet --tags 2>/dev/null || true
  fi
}

# The version tags, newest first, with or without the old "v" prefix (v2.2.0
# then 2.3.0). The order does not rely on "sort -V": the busybox of the images
# of the CI sorts a pre-release (1.0.0-rc.1) after its release. The key of a
# tag is its three numbers, padded, then "1" for a release, which comes before
# its pre-releases ("0" and the pre-release).
version_tags() {
  git tag \
    | grep -E '^v?[0-9]+\.[0-9]+\.[0-9]+(-.+)?$' \
    | awk '{
      version = $0
      sub(/^v/, "", version)
      pre = ""
      dash = index(version, "-")
      if (dash > 0) {
        pre = substr(version, dash + 1)
        version = substr(version, 1, dash - 1)
      }
      split(version, number, ".")
      printf "%010d.%010d.%010d.%s %s\n", number[1], number[2], number[3], \
        (pre == "" ? "1" : "0" pre), $0
    }' \
    | sort -r \
    | cut -d' ' -f2 \
    || true
}

fetch_history

TO_TAG="${2:-$(git describe --tags --abbrev=0 HEAD)}"
FROM_TAG="${1:-$(version_tags | grep -A1 -Fx "${TO_TAG}" | sed -n 2p || true)}"

if [ -n "${FROM_TAG}" ]; then
  RANGE="${FROM_TAG}..${TO_TAG}"
else
  # A first release: all its commits
  RANGE="${TO_TAG}"
fi

RELEASE_NOTES="release_notes.md"

echo "━━━━━━━━━━━━━━━━"
echo "📝 Generating Release Notes"
echo "━━━━━━━━━━━━━━━━"
echo ""
echo "→ From: ${FROM_TAG:-(first release)}"
echo "→ To: ${TO_TAG}"
echo "→ Output: ${RELEASE_NOTES}"
echo ""

: > "${RELEASE_NOTES}"

# Write to both stdout and the file
output() {
  echo "$@" | tee -a "${RELEASE_NOTES}"
}

footer() {
  output "---"
  output ""
  if [ -n "${FROM_TAG}" ]; then
    output "**Full Changelog**: ${FROM_TAG}...${TO_TAG}"
  else
    output "**First release**: ${TO_TAG}"
  fi
}

# Commits of the range, without the merge commits
COMMITS=$(git log "${RANGE}" --pretty=format:"%s" --no-merges)

output "## What's Changed"
output ""

if [ -z "${COMMITS}" ]; then
  output "No commit${FROM_TAG:+ since ${FROM_TAG}}."
  output ""
  footer
  exit 0
fi

# The breaking changes: the paragraph that starts with "BREAKING CHANGE:", up to
# the next blank line, as one bullet. The
# paragraph "Migration:" that follows it is added under it.
breaking_changes() {
  local commit
  for commit in $(git log "${RANGE}" --pretty=format:"%H" --no-merges); do
    git show --no-patch --pretty=format:"%b" "${commit}" | awk '
      function end_paragraph() {
        if (kind == "b") { print "- " text; after = "b" }
        else if (kind == "m") { print "  " text; after = "" }
        else { after = "" }
        kind = ""; text = ""
      }
      /^[[:space:]]*$/ { end_paragraph(); next }
      kind == "" {
        if ($0 ~ /^BREAKING CHANGE:/) {
          kind = "b"; text = $0
          sub(/^BREAKING CHANGE:[[:space:]]*/, "", text)
        }
        else if (after == "b" && $0 ~ /^Migration:/) { kind = "m"; text = $0 }
        else { kind = "x" }
        next
      }
      kind == "b" || kind == "m" { text = text " " $0 }
      END { end_paragraph() }
    '
  done
}

# Format a commit message: without the type and the scope, with its ticket at
# the end
format_commit() {
  local commit="$1"
  local type_pattern="$2"
  local message ticket

  message=$(echo "${commit}" | sed -E "s/^${type_pattern}(\([^)]*\))?: //")
  ticket=$(echo "${message}" | grep -oE "#[0-9]+" | head -n1 || true)

  if [ -n "${ticket}" ]; then
    # The pattern is quoted: a "#" at its start would anchor it at the start of
    # the message
    message="${message/"${ticket} "/}"
    echo "- ${message} (${ticket})"
  else
    echo "- ${message}"
  fi
}

# A section of the notes: section <title> <commits> <type>
section() {
  local title="$1"
  local commits="$2"
  local type="$3"

  [ -n "${commits}" ] || return 0

  output "### ${title}"
  output ""

  while read -r commit; do
    output "$(format_commit "${commit}" "${type}")"
  done <<< "${commits}"

  output ""
}

# Categorize the commits by type (following git-rules)
commits_of() {
  echo "${COMMITS}" | grep "^$1" || true
}

BREAKING_CHANGES=$(breaking_changes)

if [ -n "${BREAKING_CHANGES}" ]; then
  output "### ⚠️ BREAKING CHANGES"
  output ""
  echo "${BREAKING_CHANGES}" | while IFS= read -r line; do
    output "${line}"
  done
  output ""
fi

section "✨ Features" "$(commits_of feat)" "feat"
section "🐛 Bug Fixes" "$(commits_of fix)" "fix"
section "🚨 Hotfixes" "$(commits_of hotfix)" "hotfix"
section "⚡ Performance" "$(commits_of perf)" "perf"
section "♻️ Refactoring" "$(commits_of refactor)" "refactor"
section "📝 Documentation" "$(commits_of docs)" "docs"
section "🧪 Tests" "$(commits_of test)" "test"
section "🏗️ Build System" "$(commits_of build)" "build"
section "👷 CI/CD" "$(commits_of ci)" "ci"
section "💄 Code Style" "$(commits_of style)" "style"
section "🔧 Maintenance" "$(commits_of chore)" "chore"

# Other commits (not following convention)
TYPES="feat|fix|hotfix|docs|style|refactor|perf|test|build|ci|chore"
OTHER=$(echo "${COMMITS}" | grep -vE "^(${TYPES})" || true)

if [ -n "${OTHER}" ]; then
  output "### 📦 Other Changes"
  output ""
  while read -r commit; do
    output "- ${commit}"
  done <<< "${OTHER}"
  output ""
fi

# The issues of the release: the tickets of the subjects, and the issues that
# the footers close
output "### 🎫 Issues"
output ""
ISSUES=$({
  echo "${COMMITS}" | grep -oE "#[0-9]+" || true
  git log "${RANGE}" --pretty=format:"%b" --no-merges \
    | grep -oE "(Closes|Fixes|Resolves) #[0-9]+(, #[0-9]+)*" \
    | grep -oE "#[0-9]+" || true
} | sed 's/^#//' | sort -nu | sed 's/^/#/')

if [ -n "${ISSUES}" ]; then
  output "The issues of this release:"
  output ""
  while read -r issue; do
    output "- ${issue}"
  done <<< "${ISSUES}"
else
  output "No issues referenced in commits."
fi

output ""
footer

echo ""
echo "✅ Release notes generated: ${RELEASE_NOTES}"
