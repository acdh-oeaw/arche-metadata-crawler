# Metadata Crawler

## Functionality

A set of scripts:

* Merging metadata of a collection from inputs in [various formats](docs/metadata_formats.md)
* Validating the merged metadata
* Generating XLSX metadata templates based on the current ontology 
  (see the _horizontal_ metadata files in [metadata formats description](docs/metadata_formats.md#horizontal-metadata-file))

used for the metadata curation during ARCHE ingestions.

## Installation

### Locally

* Install PHP 8 and [composer](https://getcomposer.org/)
* Run:
  ```bash
  echo '{"minimum-stability": "dev"}' > composer.json
  composer require acdh-oeaw/arche-metadata-crawler:dev-master
  ```

### As a docker image

* Install [docker](https://www.docker.com/).

### On repo-ingestion@hephaistos

Nothing to be done. It is installed there already.

## Usage

(For a full walk-trough using repo-ingestion@hephaistos and the Wollmilchsau test collection
please look [here](docs/walktrough.md))

### On repo-ingestion@hephaistos

* Generating and validaing the metadata:
  ```bash
  /ARCHE/vendor/metacrawler/vendor/bin/arche-crawl-meta \
    <pathToMetadataDirectory> \
    <outputTtlPath> \
    <basePathOfTheCollection> \
    <idPrefix>
  ```
  e.g.
  ```bash
  /ARCHE/vendor/metacrawler/vendor/bin/arche-crawl-meta \
    /ARCHE/staging/GlaserDiaries_16674/metadata/input \
    /ARCHE/staging/GlaserDiaries_16674/metadata/metadata.ttl \
    /ARCHE/staging/GlaserDiaries_16674/data
    https://id.acdh.oeaw.ac.at/glaserdiaries
  ```
* Creating metadata templates:
  ```bash
  /ARCHE/vendor/metacrawler/vendor/bin/arche-create-metadata-template \
    <pathToDirectoryWhereTemplateShouldBeCreated> \
    all
  ```
  e.g. to create templates in the current directory
  ```bash
  /ARCHE/vendor/metacrawler/vendor/bin/arche-create-metadata-template . all
  ```

### Locally

* Generating and validaing the metadata:
  ```bash
  vendor/bin/arche-crawl-meta \
    --filecheckerOutput <pathTo_fileList.json_generatedBy_repo-filechecker> \
    <pathToCollectionData> \
    <pathToTargetMetadataFile>
  ```
  e.g.
  ```bash
  vendor/bin/arche-crawl-meta \
    metaDir \
    metadata.ttl
    `pwd`/data
    https://id.acdh.oeaw.ac.at/myCollection
  ```
* Creating metadata templates:
  ```bash
  vendor/bin/arche-create-metadata-template \
    <pathToDirectoryWhereTemplateShouldBeCreated> \
    all
  ```
  e.g. to create templates in the current directory
  ```bash
  bin/arche-create-metadata-template . all
  ```

Remarks:

* To get a list of all available parameters run:
  ```bash
  vendor/bin/arche-crawl-meta --help
  vendor/bin/arche-create-metadata-template --help
  ```

### As a docker container

* Creating metadata templates:
  Run a container mounting directory where templates should be created under `/mnt` inside the container:
  ```bash
  docker run \
    --rm -u `id -u`:`id -g`\
    -v <pathToDirectoryWhereTemplateShouldBeCreated:/mnt \
    acdhch/arche-metadata-crawler createTemplate all
  ```
  e.g. to create the templates in the current directory
  ```bash
  docker run \
    --rm -u `id -u`:`id -g` -v `pwd`:/mnt acdhch/arche-metadata-crawler createTemplate all
  ```
