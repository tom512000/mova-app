#!/bin/sh
set -e

echo "Waiting for the database..."
until php bin/console dbal:run-sql "SELECT 1" >/dev/null 2>&1; do
  sleep 1
done

echo "Running Doctrine migrations..."
php bin/console doctrine:migrations:migrate --no-interaction

echo "Setting up Messenger transports..."
php bin/console messenger:setup-transports --no-interaction

mkdir -p var/imports

exec "$@"
