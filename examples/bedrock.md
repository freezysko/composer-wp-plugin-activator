# Bedrock

[Bedrock](https://roots.io/bedrock/) is a modern WordPress boilerplate that
manages the whole site with Composer. WordPress plugins are installed as
Composer packages and land in `web/app/plugins` (Bedrock's renamed
`wp-content/plugins`). Add `freezysko/composer-wp-plugin-activator` to `require`
and it activates those Composer-installed plugins on every `composer install` /
`composer update` — no extra configuration needed.

## composer.json

```json
{
    "name": "acme/bedrock-site",
    "type": "project",
    "require": {
        "php": ">=8.1",
        "composer/installers": "^2.2",
        "roots/bedrock-autoloader": "^1.0",
        "roots/wordpress": "^6.5",
        "vlucas/phpdotenv": "^5.6",
        "wpackagist-plugin/woocommerce": "^9.0",
        "freezysko/composer-wp-plugin-activator": "^1.0"
    },
    "config": {
        "allow-plugins": {
            "composer/installers": true,
            "roots/wordpress-core-installer": true,
            "freezysko/composer-wp-plugin-activator": true
        }
    },
    "extra": {
        "wordpress-install-dir": "web/wp",
        "installer-paths": {
            "web/app/mu-plugins/{$name}/": ["type:wordpress-muplugin"],
            "web/app/plugins/{$name}/": ["type:wordpress-plugin"],
            "web/app/themes/{$name}/": ["type:wordpress-theme"]
        },
        "composer-wp-plugin-activator": {
            "plugins": "composer",
            "skip-when-wp-not-installed": true
        }
    }
}
```

## Notes

Bedrock already configures `installer-paths`, so Composer drops
`wordpress-plugin` packages into `web/app/plugins`. The activator works
zero-config there: it resolves the Composer-installed plugins and lets WP-CLI
auto-detect the WordPress path from Bedrock's `wp-cli.yml`, so `wp-path` can
stay unset. `skip-when-wp-not-installed` (the default) keeps the first build
clean — before `wp core install` has run, activation is skipped instead of
failing.
