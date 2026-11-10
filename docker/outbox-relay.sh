#!/bin/sh

set -u

OUTBOX_BATCH_SIZE="${OUTBOX_BATCH_SIZE:-100}"
OUTBOX_POLL_INTERVAL="${OUTBOX_POLL_INTERVAL:-5}"

trap 'exit 0' INT TERM

echo "[outbox-relay] Started"

while true; do
    if php bin/console app:outbox:publish \
        --limit="$OUTBOX_BATCH_SIZE" \
        --no-interaction
    then
        :
    else
        echo "[outbox-relay] Publish failed, will retry" >&2
    fi

    sleep "$OUTBOX_POLL_INTERVAL"
done
