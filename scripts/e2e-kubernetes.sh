#!/usr/bin/env bash
# End to end on Kubernetes: the official WordPress image on the CLI's throwaway k3s, with its database in a MySQL pod
# beside it, real wordpress.org for plugins and themes, and a restore onto a zip install (the CLI's throwaway MySQL)
# and back. Manual: it pulls images and downloads from wordpress.org.
#
#   (cd ../speedyspec-cli && scripts/k3s.sh up && scripts/mysql.sh up)
#   SPEEDYSPEC_BIN=/path/to/speedyspec SPEEDYSPEC_RUNNER_BIN=/path/to/linux/speedyspec scripts/e2e-kubernetes.sh
#   (cd ../speedyspec-cli && scripts/k3s.sh down && scripts/mysql.sh down)
#
# SPEEDYSPEC_RUNNER_BIN is a static Linux build of the same speedyspec for the k3s node, streamed into each runner
# pod (--runner-binary). Nothing outside a temporary directory, the k3s namespace e2e-wordpress and the
# speedyspec-mysql container is touched.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cli_repo="${SPEEDYSPEC_CLI_REPO:-$here/../speedyspec-cli}"
source_checkout="${SPEEDYSPEC_WORDPRESS_ORG_PLUGIN:-$here/../speedyspec-cli-wordpress-org}"
bin="${SPEEDYSPEC_BIN:?set SPEEDYSPEC_BIN to a speedyspec binary}"
runner="${SPEEDYSPEC_RUNNER_BIN:?set SPEEDYSPEC_RUNNER_BIN to a static Linux build of it for the k3s node}"
namespace="e2e-wordpress"
work="$(mktemp -d)"
cleanup() {
    rm -rf "$work"
}
trap cleanup EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
ok() { echo "ok: $*"; }
kubectl() { docker exec -i speedyspec-k3s kubectl "$@"; }
in_blog() { kubectl exec -n "$namespace" deploy/blog -c wordpress -- "$@"; }

export KUBECONFIG
KUBECONFIG="$("$cli_repo/scripts/k3s.sh" kubeconfig)"
[ -f "$KUBECONFIG" ] || fail "scripts/k3s.sh up has not been run"
eval "$("$cli_repo/scripts/mysql.sh" env)"
url="${SPEEDYSPEC_E2E_MYSQL:?scripts/mysql.sh up has not been run}"
local_password="$(printf '%s' "$url" | sed -E 's|mysql://root:([^@]*)@.*|\1|')"
local_port="$(printf '%s' "$url" | sed -E 's|.*:([0-9]+)$|\1|')"

# A MySQL pod in the namespace, its password generated here and kept in a Secret.
password="$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
kubectl delete namespace "$namespace" --ignore-not-found --wait=true >/dev/null
kubectl create namespace "$namespace" >/dev/null
kubectl create secret generic -n "$namespace" mysql --from-literal=password="$password" >/dev/null
kubectl apply -f - >/dev/null <<'EOF'
apiVersion: apps/v1
kind: Deployment
metadata: {name: mysql, namespace: e2e-wordpress}
spec:
  replicas: 1
  selector: {matchLabels: {app: mysql}}
  template:
    metadata: {labels: {app: mysql}}
    spec:
      containers:
        - name: mysql
          image: mysql:8.4
          env:
            - {name: MYSQL_DATABASE, value: wordpress}
            - name: MYSQL_ROOT_PASSWORD
              valueFrom: {secretKeyRef: {name: mysql, key: password}}
          readinessProbe:
            exec: {command: [sh, -c, 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -h127.0.0.1 -e "SELECT 1"']}
            periodSeconds: 2
---
apiVersion: v1
kind: Service
metadata: {name: mysql, namespace: e2e-wordpress}
spec: {selector: {app: mysql}, ports: [{port: 3306}]}
EOF
kubectl rollout status -n "$namespace" deploy/mysql --timeout=300s >/dev/null || fail "the MySQL pod did not start"

# A copy of the source plugin, so installing its packages leaves the checkout's own vendor/ alone.
source_plugin="$work/wordpress-org"
mkdir -p "$source_plugin"
(cd "$source_checkout" && tar --exclude ./vendor -cf - .) | (cd "$source_plugin" && tar -xf -)
export SPEEDYSPEC_HOME="$work/home"
"$bin" --no-plugins composer install --working-dir "$source_plugin" || fail "composer install for the source plugin"
cli() { "$bin" --plugin-dir "$here" --plugin-dir "$source_plugin" --no-interaction "$@"; }
on_k8s() { cli "$@" --namespace "$namespace" --runner-binary "$runner"; }

on_k8s app install wordpress --driver kubernetes --name blog --timeout 10m \
    --db-name wordpress --db-user root --db-password "$password" --db-host mysql \
    || fail "app install on Kubernetes"
in_blog grep -Eq "define\( 'DB_NAME', *'wordpress' \);" /var/www/html/wp-config.php \
    || fail "wp-config.php was not written into the volume"
ok "WordPress installed on Kubernetes, with wp-config.php written in a runner pod"

tables="$(on_k8s app db tables --install blog)" || fail "app db tables"
[ -z "$tables" ] || fail "expected no tables before WordPress's own installer runs, got: $tables"
on_k8s app db query "CREATE TABLE wp_options (option_id BIGINT AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) UNIQUE, option_value LONGTEXT, autoload VARCHAR(20))" --install blog >/dev/null
on_k8s app db query "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('siteurl', 'http://blog.e2e.test', 'yes'), ('widget', 'a:1:{s:3:\"url\";s:21:\"http://blog.e2e.test/\";}', 'yes')" --install blog >/dev/null
on_k8s app db query "CREATE TABLE wp_posts (ID BIGINT PRIMARY KEY, guid VARCHAR(255), post_content LONGTEXT)" --install blog >/dev/null
on_k8s app db query "INSERT INTO wp_posts VALUES (1, 'http://blog.e2e.test/?p=1', 'see http://blog.e2e.test/x')" --install blog >/dev/null
ok "the database is reached inside the cluster through wp-config.php"

on_k8s plugin install hello-dolly --install blog || fail "plugin install hello-dolly"
on_k8s plugin list --install blog | grep -q "^hello-dolly" || fail "plugin list does not show hello-dolly"
on_k8s plugin update --all --install blog || fail "plugin update --all"
in_blog test ! -e /var/www/html/.maintenance || fail "the site was left in maintenance"
on_k8s theme install astra --install blog || fail "theme install astra"
on_k8s plugin delete hello-dolly --yes --install blog || fail "plugin delete"
ok "plugins and themes from wordpress.org, on Kubernetes"

archive="$(on_k8s app backup --install blog)" || fail "app backup on Kubernetes"
on_k8s app db query "DELETE FROM wp_options WHERE option_name = 'widget'" --install blog >/dev/null
on_k8s app restore "$archive" --install blog --yes || fail "same-site restore on Kubernetes"
[ -n "$(on_k8s app db query "SELECT option_value FROM wp_options WHERE option_name = 'widget'" --install blog | tail -n +2)" ] \
    || fail "the same-site restore did not bring the row back"
ok "backup and same-site restore on Kubernetes"

# Onto a zip install on this machine, with another URL and prefix, and back.
docker exec speedyspec-mysql mysql -uroot -p"$local_password" -e 'DROP DATABASE IF EXISTS e2e_from_k8s; CREATE DATABASE e2e_from_k8s' 2>/dev/null \
    || fail "could not create the zip install's database"
cli app install wordpress --driver zip --base "$work/zip" --name zip \
    --db-name e2e_from_k8s --db-user root --db-password "$local_password" --db-host "127.0.0.1:$local_port" --table-prefix site_ \
    || fail "zip install"
cli app restore "$archive" --install zip --url http://zip.e2e.test --yes || fail "restore onto the zip install"
[ "$(cli app db query "SELECT option_value FROM site_options WHERE option_name = 'siteurl'" --install zip | tail -n +2)" = "http://zip.e2e.test" ] \
    || fail "the zip install's URL was not moved"
post="$(cli app db query "SELECT guid, post_content FROM site_posts WHERE ID = 1" --install zip | tail -n +2)"
case "$post" in *http://blog.e2e.test/?p=1*http://zip.e2e.test/x*) ;; *) fail "the moved post should keep its GUID and move its content: $post" ;; esac
ok "a Kubernetes backup restored onto a zip install, with a move"

zip_archive="$(cli app backup --install zip)" || fail "app backup of the zip install"
on_k8s app restore "$zip_archive" --install blog --url http://blog.e2e.test --table-prefix wp_ --yes || fail "restore back onto Kubernetes"
[ "$(on_k8s app db query "SELECT option_value FROM wp_options WHERE option_name = 'siteurl'" --install blog | tail -n +2)" = "http://blog.e2e.test" ] \
    || fail "the Kubernetes install's URL was not moved back"
in_blog grep -Eq "define\( 'DB_HOST', *'mysql' \);" /var/www/html/wp-config.php \
    || fail "the Kubernetes install's wp-config.php is not its own"
[ -z "$(kubectl get jobs -n "$namespace" -o name)" ] || fail "a runner Job was left behind"
ok "a zip backup restored back onto Kubernetes"

kubectl delete namespace "$namespace" --wait=false >/dev/null
echo "all passed"
