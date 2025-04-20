# =====================================
# 1. Build Stage: Compile Dependencies
# =====================================
FROM php:8.2-fpm-alpine AS builder

# Set build arguments
ARG SQLITE_EXT=false
ARG MYSQL_EXT=false
ARG MONGO_EXT=false
ARG PGSQL_EXT=false
ARG REDIS_EXT=false
ARG KAFKA_EXT=false
ARG XDEBUG_EXT=false

# Install build dependencies including Node.js, npm, and pnpm
RUN apk --no-cache add --virtual .build-deps \
    build-base \
    autoconf \
    linux-headers \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    openssl-dev \
    postgresql-dev \
    sqlite-dev \
    librdkafka-dev

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install -j$(nproc) \
    exif \
    gd \
    intl \
    mbstring \
    pcntl \
    zip \
    sockets && \
    if [ "$SQLITE_EXT" = "true" ]; then \
        docker-php-ext-install pdo_sqlite; \
    fi && \
    if [ "$MYSQL_EXT" = "true" ]; then \
        docker-php-ext-install pdo_mysql; \
    fi && \
    if [ "$MONGO_EXT" = "true" ]; then \
        pecl install mongodb && \
        docker-php-ext-enable mongodb; \
    fi && \
    if [ "$PGSQL_EXT" = "true" ]; then \
        docker-php-ext-install pdo_pgsql pgsql; \
    fi && \
    if [ "$REDIS_EXT" = "true" ]; then \
        pecl install redis && \
        docker-php-ext-enable redis; \
    fi && \
    if [ "$KAFKA_EXT" = "true" ]; then \
        pecl install rdkafka && \
        docker-php-ext-enable rdkafka; \
    fi && \
    if [ "$XDEBUG_EXT" = "true" ]; then \
        pecl install xdebug && \
        docker-php-ext-enable xdebug; \
    fi

# ======================================
# 2. Runtime Stage: Final Image
# ======================================
FROM php:8.2-fpm-alpine

# Set build arguments
ARG UID=1000
ARG GID=1000
ENV UID=${UID} GID=${GID}
ENV NGINX_ACCESS="/var/lib/nginx /var/log/nginx /var/run/nginx"
ENV PHP_ACCESS="/var/log/supervisor"
ENV SUPERVISOR_ACCESS="/var/log/supervisor"
ENV ACCESS_PATH="${NGINX_ACCESS} ${PHP_ACCESS} ${SUPERVISOR_ACCESS}"

# Install runtime dependencies including Supervisor and Nginx
RUN apk --no-cache add \
freetype \
git \
libjpeg-turbo \
libpng \
libzip \
icu \
oniguruma \
unzip \
zip \
librdkafka \
supervisor \
nginx \
nodejs \
npm && \
npm install -g pnpm && \
rm -rf /var/cache/apk/*


# Copy compiled extensions from builder stage
COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d

# Install Composer securely
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Ensure the group exists and create the user
RUN adduser -u ${UID} -D -G www-data -s /bin/bash laravel || true

# Setup permissions
WORKDIR /var/www/html
RUN chown -R laravel:www-data /var/www/html && \
    mkdir -p ${ACCESS_PATH} && \
    chown -R laravel:www-data ${ACCESS_PATH} && \
    chmod -R 775 ${ACCESS_PATH}

# Copy PHP-FPM configuration
COPY ./compose/php/rootfs/etc/php/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY ./compose/php/rootfs/etc/php/php.ini /usr/local/etc/php/php.ini

# Copy Supervisor configuration
COPY ./compose/php/rootfs/etc/supervisor/supervisord.conf /etc/supervisor/supervisord.conf

# Copy Xdebug configuration
COPY ./compose/php/rootfs/etc/php/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

# Switch to non-root user
USER laravel

# Start Supervisor to manage both services
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf", "-n"]
