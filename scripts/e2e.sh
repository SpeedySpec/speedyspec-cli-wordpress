#!/usr/bin/env bash
# End to end against a real WordPress release, real wordpress.org and a throwaway MySQL. Manual: it downloads from
# wordpress.org and api.wordpress.org, and needs the CLI's throwaway database containers:
#
#   (cd ../speedyspec-cli && scripts/mysql.sh up)
#   SPEEDYSPEC_BIN=/path/to/speedyspec scripts/e2e.sh
#   (cd ../speedyspec-cli && scripts/mysql.sh down)
#
# WORDPRESS_VERSION picks the release (its sha1, published beside it, is checked before anything is installed).
# Nothing outside a temporary directory and the speedyspec-mysql container is touched.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cli_repo="${SPEEDYSPEC_CLI_REPO:-$here/../speedyspec-cli}"
source_checkout="${SPEEDYSPEC_WORDPRESS_ORG_PLUGIN:-$here/../speedyspec-cli-wordpress-org}"
bin="${SPEEDYSPEC_BIN:?set SPEEDYSPEC_BIN to a speedyspec binary}"
version="${WORDPRESS_VERSION:-7.1.5}"
work="$(mktemp -d)"
server=""
cleanup() {
    [ -n "$server" ] && kill "$server" 2>/dev/null || true
    rm -rf "$work"
}
trap cleanup EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
ok() { echo "ok: $*"; }

eval "$("$cli_repo/scripts/mysql.sh" env)"
url="${SPEEDYSPEC_E2E_MYSQL:?scripts/mysql.sh up has not been run}"
password="$(printf '%s' "$url" | sed -E 's|mysql://root:([^@]*)@.*|\1|')"
port="$(printf '%s' "$url" | sed -E 's|.*:([0-9]+)$|\1|')"
docker exec speedyspec-mysql mysql -uroot -p"$password" -e 'DROP DATABASE IF EXISTS e2e_wordpress; CREATE DATABASE e2e_wordpress' 2>/dev/null \
    || fail "could not create the e2e database in speedyspec-mysql"

# The pinned release, checked against its published sha1, then served locally as latest.zip.
mkdir -p "$work/serve"
curl -fsSL -o "$work/serve/latest.zip" "https://wordpress.org/wordpress-$version.zip"
expected="$(curl -fsSL "https://wordpress.org/wordpress-$version.zip.sha1")"
actual="$(shasum -a 1 "$work/serve/latest.zip" | cut -d' ' -f1)"
[ "$expected" = "$actual" ] || fail "wordpress-$version.zip: sha1 $actual, published $expected"
web="$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];')"
php -S "127.0.0.1:$web" -t "$work/serve" >/dev/null 2>&1 &
server=$!
sleep 0.5

# A copy of the source plugin, so installing its packages leaves the checkout's own vendor/ alone.
source_plugin="$work/wordpress-org"
mkdir -p "$source_plugin"
(cd "$source_checkout" && tar --exclude ./vendor -cf - .) | (cd "$source_plugin" && tar -xf -)

export SPEEDYSPEC_HOME="$work/home"
export SPEEDYSPEC_WORDPRESS_DOWNLOADS="http://127.0.0.1:$web"
"$bin" --no-plugins composer install --working-dir "$source_plugin" || fail "composer install for the source plugin"
cli() { "$bin" --plugin-dir "$here" --plugin-dir "$source_plugin" --no-interaction "$@"; }

cli app install wordpress --driver zip --base "$work/site" --name e2e \
    --db-name e2e_wordpress --db-user root --db-password "$password" --db-host "127.0.0.1:$port" \
    || fail "app install"
ok "WordPress $version installed with wp-config.php"

tables="$(cli app db tables --install e2e)" || fail "app db tables"
[ -z "$tables" ] || fail "expected no tables before WordPress's own installer runs, got: $tables"
cli app db size --install e2e >/dev/null || fail "app db size"
ok "the database is reachable through wp-config.php"

cli plugin install hello-dolly --install e2e || fail "plugin install hello-dolly"
cli plugin list --install e2e
cli plugin update --all --install e2e || fail "plugin update --all"
cli theme install astra --install e2e || fail "theme install astra"
cli theme list --install e2e
cli plugin delete hello-dolly --yes --install e2e || fail "plugin delete"
ok "plugins and themes from wordpress.org"

echo "all passed; the site was at $work/site (removed on exit): point php-fpm at a copy to finish in the browser"
