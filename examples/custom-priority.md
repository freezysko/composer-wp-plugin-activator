# Custom activation order

Some plugins are foundations that many other plugins extend — WooCommerce is
the classic example. WP-CLI activates plugins alphabetically, so a dependent
plugin can fail on its first pass when its dependency has not run yet. The
`priority` key fixes this: slugs listed in `priority` are activated first, in
the given order, before the main `plugins` pass. Use it for known blockers so
dependents activate cleanly.

## composer.json

```json
{
    "name": "acme/wp-shop",
    "type": "project",
    "require": {
        "php": ">=8.1",
        "composer/installers": "^2.2",
        "wpackagist-plugin/woocommerce": "^9.0",
        "wpackagist-plugin/woocommerce-gateway-stripe": "^9.0",
        "wpackagist-plugin/woocommerce-subscriptions": "^7.0",
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
            "priority": ["woocommerce"]
        }
    }
}
```

## Notes

Here `woocommerce` activates before the main pass, so the Stripe gateway and
subscriptions add-ons find it active when their turn comes. List multiple slugs
in `priority` to control their order relative to each other. Note that
`priority` is **ignored when `plugins` is an explicit array** — the array
already controls activation order, and the activator emits a warning if both
are set. A bounded retry loop still runs after the main pass as a safety net.
See the "Plugin activation order" section of the
[README](../README.md#plugin-activation-order) for the full behaviour.
