#!/usr/bin/env bash
# Integration tests for CyberNote Security Checker on a real WordPress install.
#
# Requirements: WP-CLI, a WordPress install with this plugin active, and an
# administrator account. The install's wp-config.php must not define constants
# that the cases override (keys, WP_DEBUG*, DISALLOW_FILE_*), or must guard them
# with `defined( 'X' ) || define( ... )`.
#
#   WP_PATH=/path/to/wordpress WP_USER=admin WP_URL=http://localhost:8080 tests/integration/run.sh
#
# WP_URL is optional; when set (and the site is served there), AJAX permission
# checks are exercised over HTTP as well. Add WP_ADMIN_PASS, WP_SUB_USER and
# WP_SUB_PASS (an existing subscriber) for the logged-in checks.
set -u
DIR="$(cd "$(dirname "$0")" && pwd)"
WP_CLI="${WP_CLI:-wp}"
WP=( $WP_CLI --path="${WP_PATH:?set WP_PATH}" )
USER_ARG="--user=${WP_USER:?set WP_USER}"
fail=0

run_case() { # case [constants-file]
	local req=()
	[ -n "${2:-}" ] && req=( --require="$DIR/constants/$2" )
	CNSC_CASE="$1" "${WP[@]}" eval-file "$DIR/test-diagnostics.php" "$USER_ARG" "${req[@]}" || fail=1
}

run_case baseline
run_case updates_unknown
run_case updates_latest
run_case updates_pending
run_case php_schedule
run_case rest_open
run_case rest_blocked
run_case https_urls
run_case https_off
run_case admin_names
run_case server_display_errors
run_case keys_placeholder keys-placeholder.php
run_case keys_duplicate keys-duplicate.php
run_case keys_short keys-short.php
run_case file_mods file-mods.php
run_case debug_display debug-display.php
run_case debug_log_public debug-log.php

if [ -n "${WP_URL:-}" ]; then
	check() { # label expected actual
		if [ "$2" = "$3" ]; then echo "  ok: [http] $1"; else echo "  FAIL: [http] $1 (expected $2, got $3)"; fail=1; fi
	}
	ajax="$WP_URL/wp-admin/admin-ajax.php"
	for action in cnsc_refresh cnsc_admin_refresh; do
		code=$(curl -s -o /dev/null -w '%{http_code}' -d "action=$action" "$ajax")
		check "$action rejects anonymous requests (400 = no handler for logged-out users)" 400 "$code"
		code=$(curl -s -o /dev/null -w '%{http_code}' -d "action=$action&nonce=bogus" "$ajax")
		check "$action rejects a bogus nonce" 400 "$code"
	done

	# Logged-in checks (optional): WP_ADMIN_PASS and a subscriber account WP_SUB_USER / WP_SUB_PASS.
	if [ -n "${WP_ADMIN_PASS:-}" ] && [ -n "${WP_SUB_USER:-}" ]; then
		tmp=$(mktemp -d)
		login() { # cookiejar user pass
			curl -s -o /dev/null -c "$1" -b "$1" "$WP_URL/wp-login.php"
			curl -s -o /dev/null -c "$1" -b "$1" --data-urlencode "log=$2" --data-urlencode "pwd=$3" -d "testcookie=1" "$WP_URL/wp-login.php"
		}
		login "$tmp/admin" "$WP_USER" "$WP_ADMIN_PASS"
		login "$tmp/sub" "$WP_SUB_USER" "$WP_SUB_PASS"

		page=$(curl -s -b "$tmp/admin" "$WP_URL/wp-admin/admin.php?page=cybernote-security-checker")
		check "admin can open the plugin page" 1 "$(grep -c 'id="wsc-admin-body"' <<<"$page")"
		nonce=$(grep -o 'data-nonce="[^"]*"' <<<"$page" | head -1 | cut -d'"' -f2)
		body=$(curl -s -b "$tmp/admin" -d "action=cnsc_admin_refresh&nonce=$nonce" "$ajax")
		check "admin re-diagnose returns the results HTML" 1 "$(grep -c 'id="wsc-admin-body"' <<<"$body")"

		dash=$(curl -s -b "$tmp/admin" "$WP_URL/wp-admin/index.php")
		wnonce=$(grep -A3 'cnscRefresh(this)' <<<"$dash" | grep -o 'data-nonce="[^"]*"' | head -1 | cut -d'"' -f2)
		body=$(curl -s -b "$tmp/admin" -d "action=cnsc_refresh&nonce=$wnonce" "$ajax")
		check "admin widget re-diagnose returns the widget HTML" 1 "$(grep -c 'id="wsc-widget"' <<<"$body")"

		code=$(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/sub" "$WP_URL/wp-admin/admin.php?page=cybernote-security-checker")
		check "subscriber cannot open the plugin page" 403 "$code"
		code=$(curl -s -o /dev/null -w '%{http_code}' -b "$tmp/sub" -d "action=cnsc_admin_refresh&nonce=$nonce" "$ajax")
		check "subscriber cannot reuse the admin nonce" 403 "$code"
		body=$(curl -s -b "$tmp/sub" -d "action=cnsc_admin_refresh&nonce=$nonce" "$ajax")
		check "subscriber gets no diagnostic data" 0 "$(grep -c 'wsc-item' <<<"$body")"
		dsub=$(curl -s -b "$tmp/sub" "$WP_URL/wp-admin/index.php")
		check "subscriber dashboard has no widget" 0 "$(grep -c 'id="wsc-widget"' <<<"$dsub")"
		rm -rf "$tmp"
	fi
fi

[ "$fail" -eq 0 ] && echo "ALL PASSED" || { echo "SOME TESTS FAILED"; exit 1; }
