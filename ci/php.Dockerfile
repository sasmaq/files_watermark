ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-cli

# Stand-in for shivammathur/setup-php: the extensions used by the watermarking
# renderers and the test suite, plus composer itself.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
# bcmath is required by tecnickcom/tc-lib-pdf - Composer refuses to resolve without it.
RUN install-php-extensions gd imagick gmp mbstring dom zip bcmath @composer

# git and unzip are what `composer install --prefer-dist` needs. Nothing else: the
# app spawns no processes, so no test depends on an external binary and none skips
# for want of one.
RUN apt-get update \
	&& apt-get install -y --no-install-recommends git unzip \
	&& rm -rf /var/lib/apt/lists/*

RUN echo 'memory_limit=512M' > /usr/local/etc/php/conf.d/zz-ci.ini
