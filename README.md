# repo_file_checker 

## Functionality:

* Generates and validates RDF metadata of a collection based on:
  * Files structure on the disk
  * [repo-file-checker](https://github.com/acdh-oeaw/repo-file-checker) output
  * Metadata provided in spreadsheets and RDF files
* Generates XLSX metadata templates based on the current ontology

### Locally

* Install PHP 8 and [composer](https://getcomposer.org/)
* Run:
  ```bash
  composer require acdh-oeaw/arche-metadata-crawler:dev-master
  ```

### As a docker image

* Install [docker](https://www.docker.com/).

# Usage

## Locally

* Generating and validaint the metadata:
  ```bash
  vendor/bin/arche-crawl-meta \
    --filecheckerOutput <pathTo_fileList.json_generatedBy_repo-filechecker> \
    <pathToCollectionData> \
    <pathToTargetMetadataFile>
  ```
  e.g.
  ```bash
  vendor/bin/arche-crawl-meta --filecheckerOutput fileList.json myCollectionDir metadata.ttl
  ```
* Creating metadata templates:
  ```bash
  vendor/bin/arche-create-metadata-template <pathToDirectoryWhereTemplateShouldBeCreated> all
  ```
  e.g. to create templates in current directory
  ```bash
  bin/arche-create-metadata-template . all
  ```

Remarks:

* To get a list of all available parameters run:
  ```bash
  vendor/bin/arche-crawl-meta --help
  vendor/bin/arche-create-metadata-template --help
  ```

## On repo-ingestion@hephaistos

Will come later

## As a docker container

* To create metadata templates.  
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

