#!/bin/sh
# Runs the browser tests in the dusk container. Serves the app on port 8001 for Chromium in the browser container, waits until it answers, runs Dusk, stops the server and exits with Dusk's status. Arguments go to `php artisan dusk`, for example --filter=LoginPageTest.
set -u

log=storage/logs/dusk-server.log

# Chromium in the browser container reaches the app at this container's own address. `docker compose run` doesn't register the service name (dusk) on the network unless it's given --use-aliases, so the address is used instead of the name.
address="$(hostname -i 2>/dev/null | cut -d' ' -f1)"

# Without an address, or with an IPv6 one (which would need brackets in a URL), the URL would be broken and every test would fail in a confusing way, so stop here instead.
case "$address" in
    "" | *:*)
        echo "Couldn't find this container's IPv4 address (hostname -i printed \"$address\"), so the browser can't be told where the app is." >&2
        exit 1
        ;;
esac

APP_URL="http://$address:8001"
export APP_URL

# --no-reload keeps the container's environment variables in the server's PHP processes; without it, artisan serve hands them only a short list.
php artisan serve --host=0.0.0.0 --port=8001 --no-reload > "$log" 2>&1 &
server=$!

stop_server() {
    kill "$server" 2>/dev/null
    wait "$server" 2>/dev/null
}

# Wait up to 60 seconds for the health check route to answer.
tries=0
until php -r 'exit(@file_get_contents("http://127.0.0.1:8001/up") === false ? 1 : 0);'; do
    tries=$((tries + 1))

    if [ "$tries" -ge 60 ] || ! kill -0 "$server" 2>/dev/null; then
        echo "The app didn't start on port 8001. Its output:" >&2
        cat "$log" >&2
        stop_server
        exit 1
    fi

    sleep 1
done

php artisan dusk --without-tty "$@"
status=$?

stop_server
exit "$status"
