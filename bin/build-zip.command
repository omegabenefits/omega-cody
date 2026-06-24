#!/usr/bin/env bash
#
# Build a clean distribution zip of the plugin for the self-hosted update server.

set -euo pipefail

SLUG="omega-cody"
MAIN_FILE="omega-cody.php"
REF="${1:-HEAD}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

VERSION="$(sed -n 's/^[[:space:]]*\*\{0,1\}[[:space:]]*Version:[[:space:]]*//p' "$MAIN_FILE" | head -n1 | tr -d '[:space:]')"
if [ -z "$VERSION" ]; then
	echo "error: could not read Version from ${MAIN_FILE}" >&2
	exit 1
fi

if ! git diff --quiet HEAD -- 2>/dev/null; then
	echo "warning: working tree has uncommitted changes; building from '${REF}' (committed state only)." >&2
fi

mkdir -p dist
OUT="dist/${SLUG}-${VERSION}.zip"
rm -f "$OUT"

git archive --format=zip --prefix="${SLUG}/" -o "$OUT" "$REF"

echo "Built $OUT ($(du -h "$OUT" | cut -f1))"
echo "Top-level entries:"
unzip -Z1 "$OUT" | sed 's#/.*##' | sort -u | sed 's/^/  /'

ENV_FILE="$ROOT/bin/deploy.env"
if [ ! -f "$ENV_FILE" ]; then
	echo
	echo "note: bin/deploy.env not found; skipping upload."
	echo "      Copy bin/deploy.env.example to bin/deploy.env and fill it in to enable SFTP upload."
	exit 0
fi

set -a; . "$ENV_FILE"; set +a

: "${DEPLOY_HOST:?set DEPLOY_HOST in bin/deploy.env}"
: "${DEPLOY_USER:?set DEPLOY_USER in bin/deploy.env}"
: "${DEPLOY_REMOTE_DIR:?set DEPLOY_REMOTE_DIR in bin/deploy.env}"
PORT="${DEPLOY_PORT:-22}"
REMOTE_FILENAME="${DEPLOY_REMOTE_FILENAME:-${SLUG}.zip}"
REMOTE_PATH="${DEPLOY_REMOTE_DIR%/}/${REMOTE_FILENAME}"
TARGET="${DEPLOY_USER}@${DEPLOY_HOST}:${REMOTE_PATH}"

METADATA_URL="${DEPLOY_METADATA_URL:-https://omegabenefits.net/wp-update-server/?action=get_metadata&slug=${SLUG}}"
SERVER_VERSION="$(curl -fsS --max-time 10 "$METADATA_URL" 2>/dev/null \
	| sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n1 || true)"
if [ -n "$SERVER_VERSION" ]; then
	if [ "$SERVER_VERSION" = "$VERSION" ]; then
		echo
		echo "note: the server already advertises v${VERSION}; this is a same-version re-upload."
		echo "      Sites already on v${VERSION} will not see an update."
	elif [ "$(printf '%s\n%s\n' "$VERSION" "$SERVER_VERSION" | sort -V | tail -n1)" = "$SERVER_VERSION" ]; then
		echo
		echo "warning: local v${VERSION} is lower than the server's v${SERVER_VERSION}." >&2
		printf "Upload anyway? [y/N] "
		read -r reply </dev/tty 2>/dev/null || reply=""
		case "$reply" in
			[yY]*) ;;
			*) echo "Aborted; server keeps v${SERVER_VERSION}."; exit 1 ;;
		esac
	else
		echo
		echo "Publishing v${VERSION} (server currently advertises v${SERVER_VERSION})."
	fi
fi

echo
echo "Uploading to ${TARGET} (SFTP, port ${PORT})..."

SCP_OPTS=(-P "$PORT" -o StrictHostKeyChecking=accept-new)

if [ -n "${DEPLOY_SSH_KEY:-}" ]; then
	scp "${SCP_OPTS[@]}" -i "$DEPLOY_SSH_KEY" "$OUT" "$TARGET"
elif [ -n "${DEPLOY_PASS:-}" ]; then
	if ! command -v sshpass >/dev/null 2>&1; then
		echo "error: DEPLOY_PASS is set but sshpass is not installed." >&2
		exit 1
	fi
	sshpass -p "$DEPLOY_PASS" scp "${SCP_OPTS[@]}" "$OUT" "$TARGET"
else
	scp "${SCP_OPTS[@]}" "$OUT" "$TARGET"
fi

echo "Uploaded ${REMOTE_FILENAME}; the update server now advertises v${VERSION}."
