#!/bin/sh
# Production entrypoint. Deliberately does far less than the development one.
#
# It runs no migrations: the web container and the worker both start from this image, and
# both running doctrine:migrations:migrate at once is a race Doctrine does not guard against.
# In production that job belongs to the one-shot `migrate` service, which both wait on.
set -e

mkdir -p var/log var/share var/imports

exec "$@"
