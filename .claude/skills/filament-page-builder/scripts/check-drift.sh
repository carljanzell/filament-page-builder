#!/usr/bin/env bash
#
# Mechanical drift checks for the filament-page-builder skill.
#
# The canvas JS has no tests, and the full-screen editor hides Filament's own chrome by
# class name. Both break silently: a renamed DesignPage method turns a drag into a no-op,
# and a Filament upgrade that renames a layout class brings the sidebar back. This script
# diffs the documented contracts against the code and the installed vendor/.
#
#   1. Every `.fi-*` class page-builder.css targets still exists in vendor/filament.
#   2. Every `$wire.x` / `wire:click="x(` the canvas uses is a public member of DesignPage.
#   3. Every `el.dataset.x` the JS reads is emitted somewhere (PHP, Blade, or the JS itself).
#
# Usage: .claude/skills/filament-page-builder/scripts/check-drift.sh   (from the repo root)
# Exit status is non-zero when anything is missing.
set -uo pipefail

ROOT="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
cd "$ROOT" || exit 2

CSS=resources/css/page-builder.css
JS=resources/js/page-builder.js
PAGE=src/Filament/Pages/DesignPage.php
fail=0

ok()   { printf '  ok       %s\n' "$1"; }
miss() { printf '  MISSING  %s\n' "$1"; fail=1; }

echo "1. Filament chrome classes hidden by body.fpb-edit-mode"
if [ ! -d vendor/filament ]; then
    echo "  skipped: vendor/filament not installed (run composer install)"
else
    for class in $(grep -oE '\.fi-[a-z0-9-]+' "$CSS" | sed 's/^\.//' | sort -u); do
        if grep -rqE "(^|[\"' ])${class}([\"' ]|$)" vendor/filament/*/resources/views vendor/filament/*/src 2>/dev/null; then
            ok "$class"
        else
            miss "$class  (renamed in this Filament version? full-screen mode will leak chrome)"
        fi
    done
fi

echo "2. Livewire members the canvas calls or reads"
members=$( {
    grep -oE '\$wire\.[a-zA-Z_]+' "$JS" | sed 's/^\$wire\.//'
    grep -rhoE 'wire:click(\.[a-z]+)*="[a-zA-Z_]+' resources/views | sed -E 's/.*="//'
} | sort -u)
for member in $members; do
    if grep -qE "public function ${member}\(|public [?a-zA-Z|]+ \\\$${member}\b" "$PAGE"; then
        ok "$member"
    else
        miss "$member  (called from the canvas but not public on DesignPage)"
    fi
done

echo "3. data-* attributes the JS reads through el.dataset"
for key in $(grep -oE 'dataset\.[a-zA-Z]+' "$JS" | sed 's/^dataset\.//' | sort -u); do
    attr="data-$(printf '%s' "$key" | perl -pe 's/([A-Z])/-\l$1/g')"
    if grep -rqF "$attr" src resources/views || grep -qE "dataset\.${key} *=" "$JS"; then
        ok "$attr"
    else
        miss "$attr  (read by page-builder.js, emitted nowhere)"
    fi
done

exit "$fail"
