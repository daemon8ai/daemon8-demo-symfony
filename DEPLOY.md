# Deploy / Release Notes

This demo is designed to run locally from a fresh clone against Packagist packages `daemon8/symfony` and `daemon8/php`.

## Release check

```bash
composer install
mkdir -p var && touch var/test.db
php bin/console doctrine:migrations:migrate --env=test --no-interaction
composer test
```

## Environment

The demo expects a user-installed `daemon8` binary and a local daemon URL in `DAEMON8_URL`. No hosted environment, production credentials, or secrets are committed.
