#!/usr/bin/env bash
set -euo pipefail

# Fetch and prune remote refs
git fetch --all --prune

# Abort if working tree is dirty
if ! git diff-index --quiet HEAD --; then
  echo "Working tree dirty. Commit/stash changes before running this script."
  exit 1
fi

current_branch=$(git symbolic-ref --short HEAD)

echo ""
echo "==== Updating local branches ===="
for branch in $(git for-each-ref --format='%(refname:short)' refs/heads/); do
  upstream=$(git rev-parse --abbrev-ref "$branch@{upstream}" 2>/dev/null || true)
  if [ -n "$upstream" ]; then
    echo "---- Updating $branch from $upstream ----"
    git checkout "$branch"
    # Fast-forward only; will fail if a merge is required
    if git merge --ff-only "$upstream"; then
      echo "Updated $branch ✅"
    else
      echo "Cannot fast-forward $branch (diverged). Skipping."
    fi
  fi
done

# Return to original branch
git checkout "$current_branch"

echo ""
echo "==== Update completed ===="
echo ""
echo "💡 Tip: Use 'make git-cleanup' to remove local branches with deleted remotes"
