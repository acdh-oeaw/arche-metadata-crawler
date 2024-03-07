#!/bin/bash

# https://stackoverflow.com/questions/1215538/extract-parameters-before-last-parameter-in
if [ "$1" == "crawl" ] ; then
    php -f /opt/metacrawler/bin/arche-crawl-metadata -- "${@:2}" /mnt
elif [ "$1" == "createTemplate" ] ; then
    php -f /opt/metacrawler/bin/arche-create-metadata-template -- /mnt "${@:2}"
elif [ "$1" == "check" ] ; then
    php -f /opt/metacrawler/bin/arche-check-metadata -- "${@:2}"
else
    echo "Error: action parameter missing"
    echo ""
    echo "Usage: $0 crawl|createTemplate|check"
fi
