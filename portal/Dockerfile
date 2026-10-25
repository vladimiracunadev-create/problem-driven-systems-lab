FROM php:8.4.27-apache-trixie
RUN printf '%s\n' \
    'session.upload_progress.enabled=0' \
    'file_uploads=0' \
    'allow_url_include=0' \
    'expose_php=0' \
    'display_errors=0' \
    'log_errors=1' \
    > "$PHP_INI_DIR/conf.d/zz-security.ini"
RUN a2enmod rewrite
RUN printf "DirectoryIndex index.html index.php\n" > /etc/apache2/conf-available/pdsl-directoryindex.conf \
    && a2enconf pdsl-directoryindex
WORKDIR /var/www/html
COPY app/ /var/www/html/
