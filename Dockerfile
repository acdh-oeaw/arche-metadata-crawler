FROM php:8.2-cli
RUN curl -sSLf -o /usr/local/bin/install-php-extensions https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions && \
    chmod +x /usr/local/bin/install-php-extensions &&\
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" &&\
    sed -i -e 's/^memory_limit.*/memory_limit = -1/g' $PHP_INI_DIR/php.ini &&\
    apt update &&\
    install-php-extensions @composer ctype dom fileinfo gd iconv intl libxml mbstring simplexml xml xmlwriter zip zlib bz2 phar yaml
COPY . /opt/metacrawler
RUN cd /opt/metacrawler &&\
    composer require acdh-oeaw/repo-file-checker &&\
    composer update -o --no-dev &&\
    ln -s ../../bin/arche-crawl-meta vendor/bin/arche-crawl-meta &&\
    ln -s ../../bin/arche-create-metadata-template vendor/bin/arche-create-metadata-template &&\
    ln -s /usr/local/bin/php /usr/bin/php &&\
    chmod 777 /opt/metacrawler/vendor/acdh-oeaw/repo-file-checker/aux
ENTRYPOINT ["/opt/metacrawler/dockerinit.sh"]

