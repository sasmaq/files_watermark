ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-cli

# Stand-in for shivammathur/setup-php: the extensions used by the watermarking
# renderers and the test suite, plus composer itself.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
# bcmath is required by tecnickcom/tc-lib-pdf - Composer refuses to resolve without it.
RUN install-php-extensions gd imagick gmp mbstring dom zip bcmath @composer

# git and unzip are what `composer install --prefer-dist` needs.
#
# poppler-utils supplies `pdftoppm`, the app's one external binary (PDF flattening).
# Every other test fakes it - see PdfFlattenerTest - so the suite is green without this;
# what installing it buys is the single case that runs the *real* renderer, which would
# otherwise skip everywhere and leave poppler's own behaviour unexercised. Debian here,
# `dnf install poppler-utils` (AppStream, no EPEL) on the RHEL 9 target.
RUN apt-get update \
	&& apt-get install -y --no-install-recommends git unzip poppler-utils \
	&& rm -rf /var/lib/apt/lists/*

RUN echo 'memory_limit=512M' > /usr/local/etc/php/conf.d/zz-ci.ini
