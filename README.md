# speedyspec/wordpress

The [speedyspec](https://github.com/SpeedySpec/speedyspec-cli) CLI plugin for WordPress. With it, `speedyspec` can:

- install WordPress (`app install wordpress`): it downloads the latest release into a directory and writes
  `wp-config.php`, with the database settings, a table prefix and fresh salts;
- reach the install's database (`app db … --install <name>`), read from its `wp-config.php`;
- list, install, update and delete its plugin and theme files (`plugin …` and `theme …`), with a source plugin
  such as [speedyspec/wordpress-org](https://github.com/SpeedySpec/speedyspec-cli-wordpress-org).

speedyspec never runs WordPress. The site is served by the machine's own web server and PHP (php-fpm, say), and
the rest is done in WordPress itself: open the site to run its installer (site title, admin user), and activate
plugins and themes in wp-admin. When a plugin's files are deleted, WordPress deactivates it the next time wp-admin
loads.

## Installing WordPress

```bash
speedyspec app install wordpress --driver zip --base /srv/blog --name blog \
    --db-name blog --db-user blog --db-password '…' --db-host localhost --table-prefix wp_
```

The database and its user must exist already. The options are asked for when they are not given, and the password
is never echoed or recorded. `wp-config.php` is made from the release's own `wp-config-sample.php`, the way
WordPress's installer makes it, and keeps the sample's permissions so the web server can read it. Running the same
command again leaves an existing `wp-config.php` as it is, since new salts would log everyone out.

`SPEEDYSPEC_WORDPRESS_DOWNLOADS` replaces `https://wordpress.org` as the place `latest.zip` comes from (the tests
point it at a local server).

## Developing

```bash
composer install
vendor/bin/pest
SPEEDYSPEC_BIN=/path/to/speedyspec tests/cli.sh
```

`tests/cli.sh` drives the plugin through a speedyspec binary against a small fake release served on loopback.
`scripts/e2e.sh` runs the same against a real WordPress release and a throwaway MySQL (it downloads from
wordpress.org).
