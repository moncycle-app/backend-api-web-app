#!/bin/sh
# The entrypoint of the image. When the container is the web server (the default command) it first
#   1. makes the schema if the database has no table yet (script/db_init.php; skipped in MAINTENANCE_MODE),
#   2. starts the schedulers (supercronic) unless CRON_ENABLED is off: one for the daily job (server_conf/moncycle.crontab)
#      and, when DEMO_ENABLED is on, one for the hourly reset of the demo accounts (server_conf/moncycle-demo.crontab),
# then hands over to the php image's own entrypoint. Any other command (`docker run <image> php ...`) is run as it is.
set -eu

# Runs a command as the web user, as Apache's workers do (the app log file, if there is one, belongs to it), or as
# the current user when the container was not started as root. It replaces the shell that calls it: call it in a
# subshell.
as_web_user() {
	if [ "$(id -u)" = "0" ]; then
		exec setpriv --reuid=www-data --regid=www-data --init-groups "$@"
	else
		exec "$@"
	fi
}

if [ "${1:-}" = "apache2-foreground" ]; then
	# the database may still be starting: wait for it. No answer or no schema is a failed start, and the
	# restart policy of the container tries again.
	(cd /var/www/html/script && as_web_user php db_init.php --wait=60)

	# the same words as the boolean settings of the app (true/false, on/off, yes/no, 1/0, any case); a typo keeps the
	# default: on for CRON_ENABLED, off for DEMO_ENABLED. One scheduler per crontab: it reads its file itself, as the web user.
	case "$(printf '%s' "${CRON_ENABLED:-true}" | tr '[:upper:]' '[:lower:]')" in
		0|false|no|off)
			echo "cron: CRON_ENABLED is off, nothing is scheduled."
			;;
		*)
			(as_web_user supercronic /etc/moncycle.crontab) &
			case "$(printf '%s' "${DEMO_ENABLED:-false}" | tr '[:upper:]' '[:lower:]')" in
				1|true|yes|on) (as_web_user supercronic /etc/moncycle-demo.crontab) & ;;
			esac
			;;
	esac
fi

exec docker-php-entrypoint "$@"
