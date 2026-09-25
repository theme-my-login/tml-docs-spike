#!/usr/bin/env bash
#
# Vendor phpdoc-parser at a pinned commit.
#
# It can't be required as a normal dependency: it pins phpdocumentor/reflection-docblock
# to a branch of a personal fork whose own default branch aliases to a colliding version,
# so the graph only resolves against the lock file in its repository. Cloning it and
# installing inside it uses that lock.

set -euo pipefail

REPO='https://github.com/WordPress/phpdoc-parser.git'
REF='a1ba4bd592ae4a93b8e1fed08bc4be533324c04a'
DEST="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/.parser"

if [ -d "${DEST}/.git" ] && [ "$(git -C "${DEST}" rev-parse HEAD)" = "${REF}" ]; then
	echo "phpdoc-parser already at ${REF:0:7}"
else
	rm -rf "${DEST}"
	git init -q "${DEST}"
	git -C "${DEST}" remote add origin "${REPO}"
	git -C "${DEST}" fetch -q --depth 1 origin "${REF}"
	git -C "${DEST}" checkout -q FETCH_HEAD
fi

composer install --working-dir="${DEST}" --no-interaction --no-progress --quiet

echo "phpdoc-parser ready at ${DEST}"
