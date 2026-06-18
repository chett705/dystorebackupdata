FROM php:8.4-apache

# ដំឡើង Extensions ចាំបាច់ និងប្រព័ន្ធជំនួយ
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo pdo_mysql

# បើកដំណើរការ Apache Rewrite Module សម្រាប់ Route របស់ Laravel
RUN a2enmod rewrite

# កំណត់ Document Root របស់ Apache ទៅកាន់ Folder public របស់ Laravel
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

# 🚀 គន្លឹះសំខាន់បំផុត៖ បើកសិទ្ធិឱ្យ Apache ព្រមអានហ្វាយ .htaccess (AllowOverride All)
RUN echo '<Directory /var/www/html/public>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' >> /etc/apache2/apache2.conf

# ផ្លាស់ប្ដូរទីតាំងការងារទៅក្នុង Server
WORKDIR /var/www/html

# ⚠️ ជំហានសំខាន់៖ ត្រូវចម្លង (COPY) ហ្វាយទាំងអស់ចូលទៅក្នុង Docker មុននឹងរត់ Composer
COPY . .

# ដំឡើង Composer 
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# 🚀 រត់ composer install សម្រាប់ Production (គ្មាន dev packages ឡើយ)
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# កំណត់សិទ្ធិ Permissions លើ Folder ផ្ទុកទិន្នន័យ  
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

# 🚀 បញ្ជាដំឡើងផ្ដាច់ព្រ័ត្រ៖ ជម្រះ Config Cache, រត់រៀបចំតារាង Database និងបើក Server Apache
CMD ["/bin/sh", "-c", "php artisan config:clear && php artisan migrate --force && apache2-foreground"]