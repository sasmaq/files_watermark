ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-cli

# Stand-in for shivammathur/setup-php: the extensions used by the watermarking
# renderers and the test suite, plus composer itself.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
# bcmath is required by tecnickcom/tc-lib-pdf — Composer refuses to resolve without it.
RUN install-php-extensions gd imagick gmp mbstring dom zip bcmath @composer

# git and unzip are what `composer install --prefer-dist` needs. qpdf is what
# PdfNormalizer shells out to; without it every compressed-xref case in
# PdfNormalizerTest and PdfWatermarkerTest skips, and the PDF 1.5+ support that
# most real-world documents depend on would go untested in CI.
RUN apt-get update \
	&& apt-get install -y --no-install-recommends git unzip qpdf \
	&& rm -rf /var/lib/apt/lists/*

RUN echo 'memory_limit=512M' > /usr/local/etc/php/conf.d/zz-ci.ini
