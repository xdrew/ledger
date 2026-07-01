#!/bin/sh
set -e

# Role dispatcher for the production image. The same image serves the API, runs
# the worker, applies migrations, or seeds — selected by the first argument.
role="${1:-api}"

case "$role" in
    api)
        exec rr serve -c .rr.yaml
        ;;
    worker)
        exec rr serve -c .rr-worker.yaml
        ;;
    migrate)
        exec php bin/console doctrine:migrations:migrate --no-interaction
        ;;
    seed)
        exec php bin/console app:seed
        ;;
    console)
        shift
        exec php bin/console "$@"
        ;;
    *)
        exec "$@"
        ;;
esac
