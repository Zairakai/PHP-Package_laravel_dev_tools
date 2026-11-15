#!/usr/bin/env bash
# Writes the files of a tag in a directory. It does not use `git archive`, which leaves out what .gitattributes marks
# with export-ignore: the docs are ignored in the archive of the package, but the site needs them to build a version.
#
# Usage: export-tree.sh <tag> <directory> <path>...
set -euo pipefail

tag="${1:?usage: export-tree.sh <tag> <directory> <path>...}"
destination="${2:?usage: export-tree.sh <tag> <directory> <path>...}"
shift 2

index=$(mktemp -u)
trap 'rm -f "$index"' EXIT

mapfile -t files < <(git ls-tree -r --name-only "$tag" -- "$@")

if [ "${#files[@]}" -eq 0 ]; then
  echo "export-tree: nothing to write for $* in $tag" >&2
  exit 1
fi

mkdir -p "$destination"
GIT_INDEX_FILE="$index" git read-tree "$tag"
GIT_INDEX_FILE="$index" git checkout-index --prefix="${destination%/}/" -- "${files[@]}"
