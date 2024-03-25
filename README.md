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
  composer require acdh-oeaw/arche-metadata-crawler
  ```

### As a docker image

* Install [docker](https://www.docker.com/).
* Run the `acdhch/repo-file-checker` image mounting your data directory into it:
  ```bash
  docker run --rm -ti --entrypoint bash -u `id -u`:`id -g` \
             -v pathToYourDataDir:/data \
             acdhch/repo-file-checker
  ```
* Run the scripts, e.g.
  ```bash
  /opt/vendor/bin/arche-create-metadata-template /data al
  ```
  and
  ```
  /opt/vendor/bin/arche-crawl-meta \
    /data/metadata \
    /data/merged.ttl \
    /ARCHE/staging/GlaserDiaries_16674/data \
    https://id.acdh.oeaw.ac.at/glaserdiaries
  ```
  * if you need the [file-checker](https://github.com/acdh-oeaw/repo-file-checker),
    it is available under `/opt/vendor/bin/arche-filechecker`

### On ACDH Cluster

Nothing to be done. It is installed there already.

## Usage

(For a full walk-trough using repo-ingestion@hephaistos and the Wollmilchsau test collection
please look [here](docs/walktrough.md))

### On ACDH Cluster

First, get the arche-ingestion workload console by:

* Opening [this link](https://rancher.acdh-dev.oeaw.ac.at/dashboard/c/c-m-6hwgqq2g/explorer/apps.deployment/arche-ingestion/arche-ingestion)
  (if you are redirected to the login page, open the link once again after you log in)
* Clicking on the bluish button with three vertical dots in the top-right corner of the screen and and choosing `> Execute Shell`

Then:

* Generate and validate the metadata:
  ```bash
  /ARCHE/vendor/bin/arche-crawl-meta \
    <pathToMetadataDirectory> \
    <outputTtlPath> \
    <basePathOfTheCollection> \
    <idPrefix>
  ```
  e.g.
  ```bash
  /ARCHE/vendor/bin/arche-crawl-meta \
    /ARCHE/staging/GlaserDiaries_16674/metadata/input \
    /ARCHE/staging/GlaserDiaries_16674/metadata/metadata.ttl \
    /ARCHE/staging/GlaserDiaries_16674/data \
    https://id.acdh.oeaw.ac.at/glaserdiaries
  ```
* Create metadata templates:
  ```bash
  /ARCHE/vendor/bin/arche-create-metadata-template \
    <pathToDirectoryWhereTemplateShouldBeCreated> \
    all
  ```
  e.g. to create templates in the current directory
  ```bash
  /ARCHE/vendor/bin/arche-create-metadata-template . all
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
    acdhch/repo-file-checker createTemplate all
  ```
  e.g. to create the templates in the current directory
  ```bash
  docker run \
    --rm -u `id -u`:`id -g` -v `pwd`:/mnt acdhch/repo-file-checker createTemplate all
  ```
