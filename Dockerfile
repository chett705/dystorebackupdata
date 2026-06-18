FROM php:8.3-fpm-alpine

# ដំឡើង dependencies ប្រព័ន្ធ និង Linux Packages ដែល Laravel 13 ត្រូវការ
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    libpng-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    nodejs \
    npm \
    icu-dev \
    oniguruma-dev

# ដំឡើង PHP extensions ឱ្យបានគ្រប់គ្រាន់ (រួមទាំង intl និង mbstring)
RUN docker-php-ext-configure intl && \
    docker-php-ext-install pdo_mysql bcmath gd zip opcache intl mbstring

# យក Composer ជំនាន់ចុងក្រោយមកប្រើ
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# កំណត់ Working directory
WORKDIR /var/www/html

# ចម្លងឯកសារ composer ទៅមុនដើម្បីទាញយក packages (ជួយឱ្យ build លឿនជាងមុន)
COPY composer.json composer.lock ./

# រត់ composer install ដោយបន្ថែម --no-scripts ដើម្បីកុំឱ្យវាទាក់ error ពេល build
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# ចម្លងកូដគម្រោងទាំងអស់ចូល
COPY . .

# ដំឡើង និង Build Frontend (Vite)
RUN npm install && npm run build

# កំណត់សិទ្ធិ (Permissions) ទៅលើ folder របស់ Laravel
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# បើក Port 80
EXPOSE 80

# ដំណើរការទិន្នន័យ (migrations) និង start server ពេល deploy
CMD php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=80