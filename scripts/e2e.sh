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
docker exec speedyspec-mysql mysql -uroot -p"$password" -e 'DROP DATABASE IF EXISTS e2e_wordpress; DROP DATABASE IF EXISTS e2e_moved; CREATE DATABASE e2e_wordpress' 2>/dev/null \
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

# Backup and restore. WordPress's own installer has not run, so there are no tables yet: give the site a minimal
# options table with its URL and a serialized value that holds it.
cli app db query --install e2e "CREATE TABLE wp_options (option_name varchar(191) PRIMARY KEY, option_value longtext)" >/dev/null
cli app db query --install e2e "INSERT INTO wp_options VALUES ('siteurl','http://old.e2e.test'),('widget','a:1:{s:3:\"url\";s:21:\"http://old.e2e.test/x\";}'),('wp_user_roles','a:0:{}')" >/dev/null
cli app db query --install e2e "CREATE TABLE wp_posts (ID bigint PRIMARY KEY, guid varchar(255), post_content longtext)" >/dev/null
cli app db query --install e2e "INSERT INTO wp_posts VALUES (1,'http://old.e2e.test/?p=1','see http://old.e2e.test/x')" >/dev/null
archive="$(cli app backup --install e2e)" || fail "app backup"
cli app db query --install e2e "DELETE FROM wp_options WHERE option_name = 'widget'" >/dev/null
cli app restore "$archive" --install e2e --yes || fail "same-site restore"
[ -n "$(cli app db query --install e2e "SELECT option_value FROM wp_options WHERE option_name = 'widget'" | tail -n +2)" ] \
    || fail "the same-site restore did not bring the row back"
ok "backup and same-site restore"

docker exec speedyspec-mysql mysql -uroot -p"$password" -e 'DROP DATABASE IF EXISTS e2e_moved; CREATE DATABASE e2e_moved' 2>/dev/null \
    || fail "could not create the second e2e database"
cli app install wordpress --driver zip --base "$work/moved" --name moved \
    --db-name e2e_moved --db-user root --db-password "$password" --db-host "127.0.0.1:$port" --table-prefix site_ \
    || fail "second install"
cli app restore "$archive" --install moved --url http://new.e2e.test || fail "restore to another URL and prefix"
moved="$(cli app db query --install moved "SELECT option_value FROM site_options WHERE option_name = 'siteurl'")"
case "$moved" in *http://new.e2e.test*) ;; *) fail "the moved site's URL is: $moved" ;; esac
[ -n "$(cli app db query --install moved "SELECT option_name FROM site_options WHERE option_name = 'site_user_roles'" | tail -n +2)" ] \
    || fail "the prefixed option was not renamed"
widget="$(cli app db query --install moved "SELECT option_value FROM site_options WHERE option_name = 'widget'" | tail -n +2)"
php -r 'exit(@unserialize($argv[1]) === ["url" => "http://new.e2e.test/x"] ? 0 : 1);' "$widget" \
    || fail "the moved widget is not valid serialized data with the new URL: $widget"
post="$(cli app db query --install moved "SELECT guid, post_content FROM site_posts WHERE ID = 1" | tail -n +2)"
case "$post" in *http://old.e2e.test/?p=1*http://new.e2e.test/x*) ;; *) fail "the moved post should keep its GUID and move its content: $post" ;; esac
grep -q "e2e_moved" "$work/moved/wp-config.php" || fail "the moved site's wp-config.php is not its own"
ok "restore to another URL and prefix"

echo "all passed; the site was at $work/site (removed on exit): point php-fpm at a copy to finish in the browser"
