# Vanilla WordPress

A classic, Composer-managed WordPress install keeps its standard layout:
WordPress core in the project root and plugins in `wp-content/plugins`,
typically pulled from [WP Packages](https://wp-packages.org/) as
`wp-plugin/*` packages. Add `freezysko/composer-wp-plugin-activator` to
`require` and it activates those plugins via WP-CLI after each `composer
install` / `composer update`.

## composer.json

```json
{
    "name": "acme/wp-site",
    "type": "project",
    "repositories": [
        {
            "type": "composer",
            "url": "https://repo.wp-packages.org"
        }
    ],
    "require": {
        "php": ">=8.1",
        "composer/installers": "^2.2",
        "wp-plugin/classic-editor": "^1.6",
        "wp-plugin/wordpress-seo": "^24.0",
        "freezysko/composer-wp-plugin-activator": "^1.0"
    },
    "config": {
        "allow-plugins": {
            "composer/installers": true,
            "freezysko/composer-wp-plugin-activator": true
        }
    },
    "extra": {
        "installer-paths": {
            "wp-content/plugins/{$name}/": ["type:wordpress-plugin"]
        },
        "composer-wp-plugin-activator": {
            "plugins": "composer",
            "skip-when-wp-not-installed": true
        }
    }
}
```

## Notes

`composer/installers` places the `wp-plugin/*` packages into
`wp-content/plugins`, and the activator resolves exactly those Composer-managed
plugins. If the WordPress root is the same directory Composer runs in, WP-CLI
finds it automatically and `wp-path` can stay unset. If the WordPress install
lives in a subdirectory (or `composer install` runs from elsewhere), set
`wp-path` to the WordPress root so WP-CLI's `--path=` points at the right
install — for example `"wp-path": "public"`.
