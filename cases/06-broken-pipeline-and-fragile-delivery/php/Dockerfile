FROM php:8.2-apache
WORKDIR /var/www/html
COPY app/ /var/www/html/
RUN sed -i 's/80/8080/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf
EXPOSE 8080
