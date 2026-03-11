#!/usr/bin/env bash
set -euo pipefail

echo ""
echo "==== Git Branch Cleanup ===="
echo "This script will DELETE local branches whose remote has been deleted."
echo ""

# List local branches whose remote has been deleted
gone_branches=$(git for-each-ref --format='%(refname:short) %(upstream:track)' refs/heads/ | grep '\[gone\]' | awk '{print $1}' || true)

if [ -z "$gone_branches" ]; then
  echo "No branches with deleted remotes found. ✅"
  exit 0
fi

current_branch=$(git symbolic-ref --short HEAD)

echo "Found branches with deleted remotes:"
echo "━━━━━━━━━━━━━━━━"
echo "$gone_branches"
echo "━━━━━━━━━━━━━━━━"
echo ""
echo "⚠️  WARNING: This will permanently delete these local branches!"
echo ""

# Count branches
count=$(echo "$gone_branches" | wc -l)
echo "Total: $count branch(es) to delete"
echo ""

# Ask for confirmation
read -rp "Continue? [y/N] " -n 1
echo ""

if [[ ! $REPLY =~ ^[Yy]$ ]]; then
  echo "Aborted. No branches deleted."
  exit 0
fi

echo ""
echo "==== Deleting branches ===="
for branch in $gone_branches; do
  if [ "$branch" != "$current_branch" ]; then
    echo "Deleting: $branch"
    git branch -D "$branch"
  else
    echo "⚠️  Skipping $branch (currently checked out)"
  fi
done

echo ""
echo "==== Cleanup completed ===="
