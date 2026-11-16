#!/usr/bin/env bash
set -euo pipefail

echo ""
echo "==== Git Branch Cleanup ===="
echo "This script will delete the local branches whose remote has been deleted, when they are merged."
echo ""

# List local branches whose remote has been deleted
gone_branches=$(git for-each-ref --format='%(refname:short) %(upstream:track)' refs/heads/ | grep '\[gone\]' | awk '{print $1}' || true)

if [ -z "$gone_branches" ]; then
  echo "No branches with deleted remotes found. ✅"
  exit 0
fi

current_branch=$(git symbolic-ref --short HEAD 2> /dev/null || true)

echo "Found branches with deleted remotes:"
echo "━━━━━━━━━━━━━━━━"
echo "$gone_branches"
echo "━━━━━━━━━━━━━━━━"
echo ""
echo "A branch that is not merged is kept: its commits would be lost."
echo ""

# Count branches
count=$(echo "$gone_branches" | wc -l)
echo "Total: $count branch(es) to delete"
echo ""

# Ask for confirmation
read -rp "Continue? [y/N] " -n 1 || true
echo ""

if [[ ! $REPLY =~ ^[Yy]$ ]]; then
  echo "Aborted. No branches deleted."
  exit 0
fi

echo ""
echo "==== Deleting branches ===="
kept=()
for branch in $gone_branches; do
  if [ "$branch" = "$current_branch" ]; then
    echo "⚠️  Skipping $branch (currently checked out)"
  elif git branch -d "$branch" > /dev/null 2>&1; then
    echo "Deleted: $branch"
  else
    echo "Kept (not merged): $branch"
    kept+=("$branch")
  fi
done

if [ ${#kept[@]} -gt 0 ]; then
  echo ""
  echo "These branches hold commits that are in no other branch. To delete one anyway:"
  for branch in "${kept[@]}"; do
    echo "  git branch -D $branch"
  done
fi

echo ""
echo "==== Cleanup completed ===="
