FROM php:8.2-apache
RUN a2enmod rewrite
RUN printf "DirectoryIndex index.html index.php\n" > /etc/apache2/conf-available/pdsl-directoryindex.conf \
    && a2enconf pdsl-directoryindex
WORKDIR /var/www/html
COPY app/ /var/www/html/
