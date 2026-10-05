FROM php:8.4-apache

RUN apt-get update \
	&& apt-get install -y --no-install-recommends libfreetype6-dev libjpeg62-turbo-dev libpng-dev unzip \
	&& rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
	&& docker-php-ext-install -j$(nproc) gd pdo pdo_mysql opcache

# headers: security and cache headers. The others serve nothing here (/server-status shows request URLs)
RUN a2enmod headers \
	&& a2dismod -f status autoindex auth_basic authn_file authz_user access_compat env setenvif

# worckaround while https://github.com/chartjs/Chart.js/issues/11478 is not fixed
RUN mkdir -p /var/www/html/vendor/chartjs/ \
	&& curl -fsSL -o /var/www/html/vendor/chartjs/chart.js https://cdn.jsdelivr.net/npm/chart.js@4.5.1 \
	&& echo "48444a82d4edcb5bec0f1965faacdde18d9c17db3063d042abada2f705c9f54a  /var/www/html/vendor/chartjs/chart.js" | sha256sum -c -

# prod is the default (php.ini-production); dev is opt-in through dev.env, see server_conf/moncycleapp_php.ini
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# the scripts of script/ answer to this machine only (Require ip, see zz-moncycleapp.conf)
ENV APP_SCRIPT_ALLOW="127.0.0.1 ::1"
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

# last: composer needs the functions that this file disables
COPY ./server_conf/moncycleapp_php.ini $PHP_INI_DIR/conf.d
