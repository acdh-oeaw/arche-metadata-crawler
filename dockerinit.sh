#!/bin/bash

if [ "$1" == "crawl" ] ; then
    php -f /opt/metacrawler/bin/arche-crawl-metadata -- "${@:2}" /mnt
elif [ "$1" == "createTemplate" ] ; then
    # https://stackoverflow.com/questions/1215538/extract-parameters-before-last-parameter-in
    php -f /opt/metacrawler/bin/arche-create-metadata-template -- /mnt "${@:2}"
else
    echo "Error: action parameter missing"
    echo ""
    echo "Usage: $0 crawl|createTemplate"
fi
