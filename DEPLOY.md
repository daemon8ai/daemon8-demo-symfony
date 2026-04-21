# Deploy / Publish Swap

This repo is wired for **local development** against the [daemonai](https://github.com/daemon8ai/daemonai) monorepo via Composer path repositories. Before publishing, pushing to a shared branch, or deploying to any environment that doesn't have a sibling `../daemonai` checkout, swap to the Packagist versions.

## Swap path repo for Packagist

In `composer.json`, remove the `repositories` block and replace the `@dev` constraints on `daemon8/symfony` and `daemon8/php`:

```diff
 "require": {
     "php": ">=8.4",
-    "daemon8/php": "@dev",
-    "daemon8/symfony": "@dev",
+    "daemon8/php": "^0.1",
+    "daemon8/symfony": "^0.1",
     "...": "..."
 },
-"repositories": [
-    { "type": "path", "url": "../daemonai/sdks/php",         "options": { "symlink": true } },
-    { "type": "path", "url": "../daemonai/sdks/php-symfony", "options": { "symlink": true } }
-],
-"minimum-stability": "dev",
+"minimum-stability": "stable",
 "prefer-stable": true
```

Then:

```bash
rm -rf vendor composer.lock
composer install
composer test
```

Commit the resulting `composer.lock` as the canonical manifest for the release.

## Keeping the demo in sync with the SDK

When a new `daemon8/symfony` version ships on Packagist:

1. Bump the version constraint in `composer.json` (`^0.1` → `^0.2` or whatever).
2. `composer update daemon8/symfony daemon8/php`.
3. Run the full test battery to catch any breaking changes.
4. Tag the demo with a matching release tag so users can pin to a known-good combination.

## Environment

The demo is designed to run locally against a user-installed `daemon8` binary. No hosted environment, no production credentials, no secrets committed. If you extend this into something you deploy, add deployment-specific notes below.
