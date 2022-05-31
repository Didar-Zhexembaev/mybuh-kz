FROM php:7.4-cli
RUN apt-get update && apt-get upgrade -y \
&& apt-get install git zip unzip -y \
&& docker-php-ext-install mysqli sockets
# Install Composer
RUN curl -sS https://getcomposer.org/installer | \
php -- --install-dir=/usr/local/bin --filename=composer
WORKDIR /home