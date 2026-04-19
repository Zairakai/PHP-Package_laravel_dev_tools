#!/usr/bin/env bash
set -euo pipefail

echo ""
echo "==== Interactive Rebase ===="
echo ""

# Ensure on branch
current_branch=$(git symbolic-ref --short HEAD 2>/dev/null || true)

if [ -z "$current_branch" ]; then
  echo "Detached HEAD. Abort."
  exit 1
fi

# Check clean working tree
if ! git diff-index --quiet HEAD --; then
  echo "Working tree dirty. Commit or stash first."
  exit 1
fi

echo "Current branch : $current_branch"
echo ""

# Fetch latest remotes
git fetch --all --prune

# Build branch list
mapfile -t local_branches < <(git for-each-ref --format='%(refname:short)' refs/heads/)
mapfile -t remote_branches < <(git for-each-ref --format='%(refname:short)' refs/remotes/origin/)

echo "Select branch to rebase onto:"
echo ""

index=1

# Display local branches
echo "---- Local branches ----"
for branch in "${local_branches[@]}"; do
  echo "[$index] $branch"
  ((index++))
done

# Display remote branches
echo ""
echo "---- Remote branches (origin) ----"
for branch in "${remote_branches[@]}"; do
  echo "[$index] $branch"
  ((index++))
done

echo ""

# Read user choice
read -rp "Enter number: " choice

# Validate input
if ! [[ "$choice" =~ ^[0-9]+$ ]]; then
  echo "Invalid input."
  exit 1
fi

# Resolve selected branch
total_local=${#local_branches[@]}

if (( choice <= total_local )); then
  target_branch="${local_branches[$((choice-1))]}"
else
  target_branch="${remote_branches[$((choice-total_local-1))]}"
fi

# Prevent rebasing onto itself
if [ "$target_branch" = "$current_branch" ]; then
  echo "Cannot rebase onto the same branch."
  exit 1
fi

echo ""
echo "Rebasing '$current_branch' onto '$target_branch'..."
echo ""

# Rebase
if git rebase "$target_branch"; then
  echo ""
  echo "==== Rebase OK ===="
  echo ""

  read -rp "Force push now? [y/N] " -n 1
  echo ""

  if [[ $REPLY =~ ^[Yy]$ ]]; then
    git push --force-with-lease
    echo "Push completed."
  else
    echo "Push skipped."
  fi

else
  echo ""
  echo "==== Conflict ===="
  echo "Resolve conflicts, then:"
  echo "  git rebase --continue"
  echo ""
  echo "Or abort:"
  echo "  git rebase --abort"
  exit 1
fi
