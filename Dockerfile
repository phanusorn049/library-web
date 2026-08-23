FROM php:8.3-cli

RUN apt-get update \
	&& apt-get install -y --no-install-recommends libcurl4-openssl-dev \
	&& docker-php-ext-install mysqli curl \
	&& rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . .

EXPOSE 10000
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} -t /var/www/html"]
