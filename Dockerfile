FROM php:8.4-apache

RUN apt-get update \
	&& apt-get install -y --no-install-recommends libfreetype6-dev libjpeg62-turbo-dev libpng-dev unzip \
	&& rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
	&& docker-php-ext-install -j$(nproc) gd pdo pdo_mysql opcache

# headers: security and cache headers. rewrite: the 413 on the Content-Length of a request (zz-moncycleapp.conf).
# The others serve nothing here (/server-status shows request URLs)
RUN a2enmod headers rewrite \
	&& a2dismod -f status autoindex auth_basic authn_file authz_user access_compat env setenvif

# no Apache access log: the app writes its own (lib/log.php, README "Logs"), whose `http.request` line
# has no query string, where this one has `DELETE /api/totp?code=<TOTP code>` and the dates of a read.
# ErrorLog stays (stderr). The build fails if a CustomLog directive is left (apache2.conf has a comment that names it).
RUN sed -i '/CustomLog/d' /etc/apache2/sites-available/*.conf /etc/apache2/conf-available/*.conf \
	&& a2disconf -q other-vhosts-access-log \
	&& ! grep -rnE '^[[:space:]]*CustomLog' /etc/apache2

# worckaround while https://github.com/chartjs/Chart.js/issues/11478 is not fixed
RUN mkdir -p /var/www/html/vendor/chartjs/ \
	&& curl -fsSL -o /var/www/html/vendor/chartjs/chart.js https://cdn.jsdelivr.net/npm/chart.js@4.5.1 \
	&& echo "48444a82d4edcb5bec0f1965faacdde18d9c17db3063d042abada2f705c9f54a  /var/www/html/vendor/chartjs/chart.js" | sha256sum -c -

# the scheduler of the daily job (CRON_ENABLED, server_conf/moncycle.crontab). supercronic is one static binary: unlike
# the system cron it has no daemon, pid file or spool to write, so it runs in a read-only container, as the web user,
# with the environment of the container (the DB settings the job needs). Pinned and checked like chart.js above.
RUN arch="$(dpkg --print-architecture)" \
	&& case "$arch" in \
		amd64) sha256=a53ae236602c7338aba3fbaff40bda6300eae3b9fedb8261eb06cfe3724430c1 ;; \
		arm64) sha256=02aa0cb229ba09050cba6638059dadb9eedc2276632ea43d6a57a2f8c1629dd5 ;; \
		*) echo "no supercronic build for $arch" >&2; exit 1 ;; \
	esac \
	&& curl -fsSL -o /usr/local/bin/supercronic "https://github.com/aptible/supercronic/releases/download/v0.2.49/supercronic-linux-$arch" \
	&& echo "$sha256  /usr/local/bin/supercronic" | sha256sum -c - \
	&& chmod 755 /usr/local/bin/supercronic

# prod is the default (php.ini-production); dev is opt-in through dev.env, see server_conf/moncycleapp_php.ini
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# the scripts of script/ answer to this machine only (Require ip, see zz-moncycleapp.conf)
ENV APP_SCRIPT_ALLOW="127.0.0.1 ::1"
# the news page of the web app: the app reads it as the NEWS_URL setting, and Apache puts it in the CSP's
# connect-src (zz-moncycleapp.conf). Same default as config.docker.php; set it empty to turn the banner off.
ENV NEWS_URL="https://www.moncycle.app/actu.html"
# the daily job (cron.php at 3:30, container time zone) runs inside the container, and so does the hourly reset of the
# demo accounts when DEMO_ENABLED (a setting of the app, off by default); false to run them from the host (README,
# "Security") or not at all.
ENV CRON_ENABLED="true"
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY --from=composer/composer:latest-bin /composer /usr/bin/composer
COPY ./server_conf/zz-moncycleapp.conf /etc/apache2/conf-enabled/zz-moncycleapp.conf
COPY ./www_data /var/www/html/
COPY ./www_data/config.docker.php /var/www/html/config.php

# dependencies from composer.lock; the code is root-owned and not writable: the app never writes to disk
RUN cd /var/www/html \
	&& composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --classmap-authoritative \
	&& composer clear-cache \
	&& chown -R root:root /var/www/html \
	&& chmod -R a+rX,go-w /var/www/html

# where LOG_OUTPUT can point a file of the app log (open_basedir allows this directory only). Owned by the web
# user so that a named volume mounted here inherits it; a bind mount takes the host directory's owner (chown 33:33).
RUN mkdir -p /var/log/moncycle \
	&& chown www-data:www-data /var/log/moncycle \
	&& chmod 750 /var/log/moncycle

# the entrypoint makes the schema on a first launch and starts the scheduler, then hands over to Apache
COPY ./server_conf/moncycle.crontab /etc/moncycle.crontab
COPY ./server_conf/moncycle-demo.crontab /etc/moncycle-demo.crontab
COPY ./server_conf/docker-entrypoint.sh /usr/local/bin/moncycle-entrypoint
RUN chmod 644 /etc/moncycle.crontab /etc/moncycle-demo.crontab \
	&& chmod 755 /usr/local/bin/moncycle-entrypoint
ENTRYPOINT ["moncycle-entrypoint"]
# an ENTRYPOINT here drops the CMD of the php image
CMD ["apache2-foreground"]

# last: composer needs the functions that this file disables
COPY ./server_conf/moncycleapp_php.ini $PHP_INI_DIR/conf.d
