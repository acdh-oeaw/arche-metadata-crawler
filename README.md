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

* Install PHP and [composer](https://getcomposer.org/)
* Run:
  ```bash
  composer require acdh-oeaw/arche-metadata-crawler
  ```

### As a docker image

* Install [docker](https://www.docker.com/).
* Run the `acdhch/arche-ingest` image mounting your data directory into it:
  ```bash
  docker run --rm -ti --entrypoint bash -u `id -u`:`id -g` \
             -v pathToYourDataDir:/data \
             acdhch/arche-ingest
  ```
* Run the scripts, e.g.
  ```bash
  arche-create-metadata-template /data all
  ```
  and
  ```
  arche-crawl-meta \
    /data/metadata \
    /data/merged.ttl \
    /ARCHE/staging/GlaserDiaries_16674/data \
    https://id.acdh.oeaw.ac.at/glaserdiaries
  ```
  * if you need the [file-checker](https://github.com/acdh-oeaw/repo-file-checker),
    you can just run it with `arche-filechecker`

### On ACDH Cluster

Nothing to be done. It is installed there already.

## Usage

(For a full walk-trough using arche-ingestion@acdh-cluster and the Wollmilchsau test collection
please look [here](docs/walktrough.md))

### On ACDH Cluster

First, get the arche-ingestion workload console as described [here](https://github.com/acdh-oeaw/arche-ingest/blob/master/docs/acdh-cluster.md)

Then:

* Generate and validate the metadata:
  * Run the `arche-crawl-meta` script:
    ```bash
    /ARCHE/vendor/bin/arche-crawl-meta \
      <pathToMetadataDirectory> \
     --filecheckerReportDir <pathToTheFileCheckerReportDirectory> \
      <outputTtlPath> \
      <basePathOfTheCollection> \
      <idPrefix> \
      2>&1 | tee <pathToLogFile>
    ```
    e.g.
    ```bash
    /ARCHE/vendor/bin/arche-crawl-meta \
      /ARCHE/staging/GustavMahlerArchiv_22334/metadata \
      --filecheckerReportDir /ARCHE/staging/GustavMahlerArchiv_22334/checkReports/2024_04_08_09_19_24 \
      /ARCHE/staging/GustavMahlerArchiv_22334/scriptFiles/metadata.ttl \
      /ARCHE/staging/GustavMahlerArchiv_22334/data \
      https://id.acdh.oeaw.ac.at/GustavMahlerArchiv \
      2>&1 | tee /ARCHE/staging/GustavMahlerArchiv_22334/scriptFiles/metadata.log
    ```
    * If you are want to skip the checks (which speeds up the process significantly), add the `--noCheck` parameter, e.g.
      ```bash
      /ARCHE/vendor/bin/arche-crawl-meta \
        /ARCHE/staging/GustavMahlerArchiv_22334/metadata \
        --filecheckerReportDir /ARCHE/staging/GustavMahlerArchiv_22334/checkReports/2024_04_08_09_19_24 \
        /ARCHE/staging/GustavMahlerArchiv_22334/scriptFiles/metadata.ttl \
        /ARCHE/staging/GustavMahlerArchiv_22334/data \
        https://id.acdh.oeaw.ac.at/GustavMahlerArchiv \
        --noCheck \
        2>&1 | tee /ARCHE/staging/GustavMahlerArchiv_22334/scriptFiles/metadata.log

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
    --filecheckerReportDir pathToDirectoryWithFilecheckerOutput \
    pathToInputMetadataDir \
    mergedMetadataFilePath \
    pathToCollectionData \
    pathToTargetMetadataFile
  ```
  e.g.
  ```bash
  vendor/bin/arche-crawl-meta \
    --filecheckerReportDir reports/2024_03_01_12_45_23 \
    metaDir \
    metadata.ttl \
    `pwd`/data \
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

* Generating and validaing the metadata:  
  Run a container mounting directory structure inside the container 
  and overridding the command to be run with the arche-crawl-meta:
  ```bash
  docker run \
    --rm -u `id -u`:`id -g`\
    -v pathInHost:/mnt \
    --entrypoint arche-crawl-meta \
    acdhch/arche-ingest \
    --filecheckerReportDir pathToDirectoryWithFilecheckerOutput \
    pathToInputMetadataDir \
    mergedMetadataFilePath \
    pathToCollectionData \
    pathToTargetMetadataFile
  ```
  e.g. to use with pahts relatively to the current working directory
  ```bash
  docker run \
    --rm -u `id -u`:`id -g`\
    -v `pwd`:/mnt \
    --entrypoint arche-crawl-meta \
    acdhch/arche-ingest \
    --filecheckerReportDir /mnt/reports/2024_03_01_12_45_23 \
    /mnt/metaDir \
    /mnt/metadata.ttl \
    /mnt/data \
    https://id.acdh.oeaw.ac.at/myCollection
  ```
* Creating metadata templates:  
  Run a container mounting directory where templates should be created under `/mnt` inside the container 
  and overridding the command to be run with the arche-create-metadata-template:
  ```bash
  docker run \
    --rm -u `id -u`:`id -g`\
    -v pathToDirectoryWhereTemplateShouldBeCreated:/mnt \
    --entrypoint arche-create-metadata-template
    acdhch/arche-ingest \
    /mnt all
  ```
  e.g. to create the templates in the current directory
  ```bash
  docker run \
    --rm -u `id -u`:`id -g` \
    -v `pwd`:/mnt \
    --entrypoint arche-create-metadata-template \
    acdhch/arche-ingest \
    /mnt all
  ```
