#!/bin/sh
# Build a zip of the site for a Netlify test (drag and drop it at app.netlify.com/drop, or onto your existing site's Deploys page).
# PHP (booking form, coven sign-up, admin) doesn't run on Netlify; everything else does, including the live world.
set -e
cd "$(dirname "$0")/.."
OUT="${1:-mortez-netlify.zip}"; TMP=$(mktemp -d)
cp -r site/. "$TMP/"
rm -f "$TMP"/*.php "$TMP/.htaccess" "$TMP/merch-sync.php"
cp deploy/netlify-preview_headers "$TMP/_headers"; cp deploy/netlify_redirects "$TMP/_redirects"
(cd "$TMP" && zip -qr - .) > "$OUT"; rm -rf "$TMP"; echo "$OUT"
