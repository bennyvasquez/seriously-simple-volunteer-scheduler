#!/usr/bin/env bash
# Opens a GitHub issue when the npm registry has a newer axe-core than the one in tests/Browser/axe/VERSION, unless an open issue for that version already exists. The monthly workflow in .github/workflows/axe-update.yml runs it. With --dry-run it says what it would do and creates nothing. Needs curl, jq and gh (with GH_TOKEN set).
set -euo pipefail

dry_run=false
if [ "${1:-}" = "--dry-run" ]; then
  dry_run=true
fi

root="$(cd "$(dirname "$0")/../.." && pwd)"
current="$(tr -d '[:space:]' < "$root/tests/Browser/axe/VERSION")"
latest="$(curl -fsS https://registry.npmjs.org/axe-core/latest | jq -r .version)"

if [ -z "$latest" ] || [ "$latest" = "null" ]; then
  echo "Couldn't read the latest axe-core version from the npm registry." >&2
  exit 1
fi

echo "Current axe-core: $current. Latest on npm: $latest."

# sort -V puts the older version first. If the newest of the two is the current one, there's nothing to do.
newest="$(printf '%s\n%s\n' "$current" "$latest" | sort -V | tail -n 1)"
if [ "$latest" = "$current" ] || [ "$newest" = "$current" ]; then
  echo "axe-core is up to date. Nothing to do."
  exit 0
fi

title="Update axe-core to $latest"

existing="$(gh issue list --state open --search "\"$title\" in:title" --json title --jq "[.[] | select(.title == \"$title\")] | length")"
if [ "$existing" != "0" ]; then
  echo "An open issue titled \"$title\" already exists. Nothing to do."
  exit 0
fi

body="axe-core $latest is out and the copy in tests/Browser/axe is $current. The steps to update it are in the \"Updating axe-core\" section of [DEVELOPMENT.md](https://github.com/${GITHUB_REPOSITORY:-bennyvasquez/seriously-simple-volunteer-scheduler}/blob/main/DEVELOPMENT.md#updating-axe-core). This issue was opened by the monthly check in .github/workflows/axe-update.yml."

if [ "$dry_run" = true ]; then
  echo "Dry run: would open the issue \"$title\" labeled tests with this body:"
  echo "$body"
  exit 0
fi

gh issue create --title "$title" --label tests --body "$body"
