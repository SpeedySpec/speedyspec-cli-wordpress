#!/usr/bin/env bash
# Drives this plugin through a speedyspec binary against a small fake WordPress release, served on loopback by PHP's
# built-in server. Nothing reaches the internet or a database.
#
#   SPEEDYSPEC_BIN=/path/to/speedyspec tests/cli.sh
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
bin="${SPEEDYSPEC_BIN:?set SPEEDYSPEC_BIN to a speedyspec binary}"
work="$(mktemp -d)"
server=""
cleanup() {
    [ -n "$server" ] && kill "$server" 2>/dev/null || true
    chmod -R u+w "$work" 2>/dev/null || true
    rm -rf "$work"
}
trap cleanup EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
ok() { echo "ok: $*"; }

secret="s3cr3t-'\"\$x"

# A fake release: the real sample config, a single-file plugin and an empty themes directory.
mkdir -p "$work/release/wordpress/wp-content/plugins" "$work/release/wordpress/wp-content/themes" "$work/serve"
cp "$here/tests/fixtures/wp-config-sample.php" "$work/release/wordpress/"
printf '<?php\n/*\nPlugin Name: Hello Dolly\nVersion: 1.7.2\n*/\n' > "$work/release/wordpress/wp-content/plugins/hello.php"
printf '<?php // Silence is golden.\n' > "$work/release/wordpress/wp-content/plugins/index.php"
php -r '
$zip = new ZipArchive();
$zip->open($argv[2], ZipArchive::CREATE);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[1], FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $file) {
    $name = substr($file->getPathname(), strlen($argv[1]) + 1);
    $file->isDir() ? $zip->addEmptyDir($name) : $zip->addFile($file->getPathname(), $name);
}
$zip->close();' "$work/release" "$work/serve/latest.zip"

port="$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];')"
php -S "127.0.0.1:$port" -t "$work/serve" >/dev/null 2>&1 &
server=$!
for _ in $(seq 50); do
    curl -fs -o /dev/null "http://127.0.0.1:$port/latest.zip" && break
    sleep 0.1
done

export SPEEDYSPEC_HOME="$work/home"
export SPEEDYSPEC_WORDPRESS_DOWNLOADS="http://127.0.0.1:$port"
cli() { "$bin" --plugin-dir "$here" --no-interaction "$@"; }

out="$(cli app install wordpress --driver zip --base "$work/site" --name wp \
    --db-name blog --db-user blogger --db-password "$secret" --db-host 127.0.0.1:3307 --table-prefix site_ 2>&1)" \
    || fail "install: $out"
case "$out" in *s3cr3t*) fail "the password was printed: $out" ;; esac
[ -f "$work/site/wp-config.php" ] || fail "no wp-config.php"
ok "install wrote wp-config.php"

printf '<?php PhpToken::tokenize(file_get_contents($argv[1]), TOKEN_PARSE); echo "parses\\n";' > "$work/parse.php"
[ "$("$bin" --no-plugins php "$work/parse.php" "$work/site/wp-config.php")" = "parses" ] || fail "wp-config.php does not parse"
ok "wp-config.php parses in the embedded PHP"

db="$(cli self database --install wp)" || fail "self database: $db"
case "$db" in *s3cr3t*) fail "self database printed the password" ;; esac
php -r '$d = json_decode($argv[1], true); exit($d["host"] === "127.0.0.1" && $d["port"] === 3307 && $d["database"] === "blog" && $d["table_prefix"] === "site_" ? 0 : 1);' "$db" \
    || fail "self database gave $db"
ok "the database settings read back: $db"

before="$(shasum "$work/site/wp-config.php")"
again="$(cli app install wordpress --driver zip --base "$work/site" --name wp --force \
    --db-name blog --db-user blogger --db-password "$secret" 2>&1)" || fail "second install: $again"
case "$again" in *"left as it is"*) ;; *) fail "the second install did not leave wp-config.php alone: $again" ;; esac
[ "$before" = "$(shasum "$work/site/wp-config.php")" ] || fail "wp-config.php changed"
ok "running the install again leaves wp-config.php alone"

list="$(cli plugin list --install wp)" || fail "plugin list: $list"
[ "$list" = "$(printf 'hello\tHello Dolly\t1.7.2')" ] || fail "plugin list gave: $list"
ok "plugin list reads the headers"

# A setup that fails (the root is read-only) names the problem, not the password.
rm "$work/site/wp-config.php"
php -r '$p = $argv[1]; $r = json_decode(file_get_contents($p), true); $r["status"] = "files"; $r["setup_done"] = 0; file_put_contents($p, json_encode($r));' \
    "$SPEEDYSPEC_HOME/installs/wp.json"
chmod 555 "$work/site"
if failed="$(cli app install wordpress --driver zip --base "$work/site" --name wp \
    --db-name blog --db-user blogger --db-password "$secret" 2>&1)"; then
    fail "a setup into a read-only root succeeded"
fi
chmod 755 "$work/site"
case "$failed" in *s3cr3t*) fail "the failure printed the password: $failed" ;; esac
case "$failed" in *"setup stopped at write-config.php"*) ;; *) fail "the failure did not name the task: $failed" ;; esac
ok "a failed setup names the task and not the password"
echo "all passed"
