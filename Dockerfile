FROM php:8.3-cli

RUN docker-php-ext-install mysqli

WORKDIR /var/www/html
COPY . .

EXPOSE 10000
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} -t /var/www/html"]
