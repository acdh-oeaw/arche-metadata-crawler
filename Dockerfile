FROM php:8.2-cli
RUN curl -sSLf -o /usr/local/bin/install-php-extensions https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions && \
    chmod +x /usr/local/bin/install-php-extensions &&\
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" &&\
    sed -i -e 's/^memory_limit.*/memory_limit = -1/g' $PHP_INI_DIR/php.ini &&\
    apt update &&\
    install-php-extensions @composer ctype dom fileinfo gd iconv libxml mbstring simplexml xml xmlwriter zip zlib
COPY . /opt/metacrawler
RUN cd /opt/metacrawler &&\
    composer update -o --no-dev
ENTRYPOINT ["/opt/metacrawler/dockerinit.sh"]

