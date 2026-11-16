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
  upstream=$(git rev-parse --abbrev-ref "$branch@{upstream}" 2> /dev/null || true)
  if [ -z "$upstream" ]; then
    continue
  fi

  echo "---- Updating $branch from $upstream ----"
  # Fast-forward only, without a checkout: a branch that is checked out in another worktree, or that diverged, is skipped
  if [ "$branch" = "$current_branch" ]; then
    update=(git merge --ff-only "$upstream")
  else
    update=(git fetch . "$upstream:$branch")
  fi

  if "${update[@]}"; then
    echo "Updated $branch ✅"
  else
    echo "Cannot fast-forward $branch (diverged or checked out elsewhere). Skipping."
  fi
done

echo ""
echo "==== Update completed ===="
echo ""
echo "💡 Tip: Use 'make git-cleanup' to remove local branches with deleted remotes"
