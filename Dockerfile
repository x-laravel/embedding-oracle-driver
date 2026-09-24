ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli-bookworm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    git \
    unzip \
    libaio1 \
    wget \
    && rm -rf /var/lib/apt/lists/*

# Install Oracle Instant Client
WORKDIR /opt/oracle
RUN wget https://download.oracle.com/otn_software/linux/instantclient/211000/instantclient-basic-linux.x64-21.1.0.0.0.zip \
    && wget https://download.oracle.com/otn_software/linux/instantclient/211000/instantclient-sdk-linux.x64-21.1.0.0.0.zip \
    && unzip instantclient-basic-linux.x64-21.1.0.0.0.zip \
    && unzip instantclient-sdk-linux.x64-21.1.0.0.0.zip \
    && rm *.zip \
    && mv instantclient_21_1 instantclient

# Configure PHP for Oracle
ENV LD_LIBRARY_PATH=/opt/oracle/instantclient
RUN echo 'instantclient,/opt/oracle/instantclient' | pecl install oci8 \
    && docker-php-ext-enable oci8 \
    && docker-php-ext-install pdo_sqlite

# Install Composer
COPY --from=composer/composer:latest-bin /composer /usr/bin/composer

WORKDIR /app
