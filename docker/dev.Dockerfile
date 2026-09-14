FROM php:8.2-apache AS app

RUN a2enmod rewrite \
    && docker-php-ext-install mysqli \
    && sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf


FROM alpine:3.24 AS tailwind

ARG TAILWIND_VERSION=4.3.3

RUN apk add --no-cache ca-certificates libgcc libstdc++ \
    && wget -qO /usr/local/bin/tailwindcss \
        "https://github.com/tailwindlabs/tailwindcss/releases/download/v${TAILWIND_VERSION}/tailwindcss-linux-x64-musl" \
    && echo "a04d34ceacc8f52cbe8920ad846cdeb61d3d0021dba32db0d1f77c9d9fad7a6c  /usr/local/bin/tailwindcss" \
        | sha256sum -c - \
    && chmod 0755 /usr/local/bin/tailwindcss
