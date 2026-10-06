#!/usr/bin/env bash
# Builds the documentation of every released version and of main, and puts them in one site (./public):
#
#   /            the latest released version
#   /1.4.0/      each released version, built from its own tag (its sources, its docs)
#   /next/       main
#   /versions.json   what the version selector lists
#
# GitLab Pages has one site per project and deploys only from the default branch, so this runs there and
# rebuilds the versions from their tags: nothing has to be kept between two runs.
set -euo pipefail

cd "$(dirname "$0")/../.."

# In CI the checkout belongs to another user than the one of the job: git refuses it otherwise.
if [ -n "${CI:-}" ]; then
  git config --global --add safe.directory "$PWD"
fi

root=$(git rev-parse --show-toplevel)
out="$root/public"
work=$(mktemp -d)
export npm_config_cache="${npm_config_cache:-$root/.npm}"

# Where the site is served: "/" on GitLab.com (unique domain), "/Repository/" on GitHub Pages.
site_root="${DOCS_SITE_ROOT:-/}"
# What a version needs to build its docs (the docs, and the files they read).
archive_paths="${DOCS_ARCHIVE_PATHS:-docs README.md composer.json src}"

rm -rf "$out"
mkdir -p "$out"

# The repository the sources are linked to (GitLab gives it; GitHub is built below).
export DOCS_REPOSITORY_URL="${DOCS_REPOSITORY_URL:-${CI_PROJECT_URL:-}}"

# On GitHub Actions the build information has other names: give it the ones of the docs config.
if [ -n "${GITHUB_ACTIONS:-}" ]; then
  export CI_PROJECT_URL="${GITHUB_SERVER_URL}/${GITHUB_REPOSITORY}"
  export CI_PIPELINE_ID="${GITHUB_RUN_ID}"
  export CI_PIPELINE_URL="${CI_PROJECT_URL}/actions/runs/${GITHUB_RUN_ID}"
  export CI_COMMIT_REF_NAME="${GITHUB_REF_NAME}"
  export CI_COMMIT_SHA="${GITHUB_SHA}"
  CI_COMMIT_SHORT_SHA=$(git rev-parse --short "$GITHUB_SHA")
  export CI_COMMIT_SHORT_SHA
  CI_COMMIT_TIMESTAMP=$(git log -1 --format=%cI "$GITHUB_SHA")
  export CI_COMMIT_TIMESTAMP
fi

export DOCS_REPOSITORY_URL="${DOCS_REPOSITORY_URL:-${CI_PROJECT_URL:-}}"

# What built main, to give back to it at the end.
main_ref="${CI_COMMIT_REF_NAME-}"
main_sha="${CI_COMMIT_SHA-}"
main_short="${CI_COMMIT_SHORT_SHA-}"
main_time="${CI_COMMIT_TIMESTAMP-}"

git fetch --quiet --tags origin 2>/dev/null || true

# Build the docs of a source tree: build <directory> <version> <base> <destination> <source ref>
build() {
  (
    cd "$1/docs"
    npm ci --no-audit --no-fund --silent
    DOCS_VERSION="$2" DOCS_BASE="$3" DOCS_ROOT="$site_root" DOCS_SOURCE_REF="$5" npm run build --silent
    rm -rf "$4"
    mkdir -p "$4"
    cp -R .vitepress/dist/. "$4/"
  )
}

# The released versions that have a documentation, oldest first.
versions=()
for tag in $(git tag -l | grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' | sort -V); do
  if git cat-file -e "$tag:docs/package.json" 2>/dev/null; then
    versions+=("$tag")
  fi
done

for tag in "${versions[@]}"; do
  echo "== documentation of $tag"
  mkdir -p "$work/$tag"
  # shellcheck disable=SC2086
  git archive "$tag" $archive_paths | tar -x -C "$work/$tag"

  # What built it: the tag, and this pipeline.
  export CI_COMMIT_REF_NAME="$tag"
  CI_COMMIT_SHA=$(git rev-parse "$tag^{commit}")
  export CI_COMMIT_SHA
  CI_COMMIT_SHORT_SHA=$(git rev-parse --short "$tag^{commit}")
  export CI_COMMIT_SHORT_SHA
  CI_COMMIT_TIMESTAMP=$(git log -1 --format=%cI "$tag")
  export CI_COMMIT_TIMESTAMP

  build "$work/$tag" "$tag" "${site_root}${tag}/" "$out/$tag" "$tag"
done

# The latest version is also the root of the site.
latest=""
if [ "${#versions[@]}" -gt 0 ]; then
  latest="${versions[-1]}"
  echo "== root is $latest"
  build "$work/$latest" "$latest" "$site_root" "$work/root" "$latest"
  cp -R "$work/root/." "$out/"
fi

# main.
for name in CI_COMMIT_REF_NAME CI_COMMIT_SHA CI_COMMIT_SHORT_SHA CI_COMMIT_TIMESTAMP; do
  unset "$name"
done
[ -n "$main_ref" ] && export CI_COMMIT_REF_NAME="$main_ref"
[ -n "$main_sha" ] && export CI_COMMIT_SHA="$main_sha"
[ -n "$main_short" ] && export CI_COMMIT_SHORT_SHA="$main_short"
[ -n "$main_time" ] && export CI_COMMIT_TIMESTAMP="$main_time"
echo "== documentation of main (next)"
build "$root" "next" "${site_root}next/" "$out/next" "main"

# Nothing released yet: the root is main.
if [ -z "$latest" ]; then
  build "$root" "next" "$site_root" "$work/root" "main"
  cp -R "$work/root/." "$out/"
fi

# The selector reads this file.
{
  printf '{"latest":%s,"versions":[' "$([ -n "$latest" ] && printf '"%s"' "$latest" || printf 'null')"
  for ((i = ${#versions[@]} - 1; i >= 0; i--)); do
    printf '"%s"' "${versions[$i]}"
    [ "$i" -gt 0 ] && printf ','
  done
  printf '],"next":true}\n'
} >"$out/versions.json"

echo "== site written in $out"
cat "$out/versions.json"
