# Walktrough

Let's try to go trough the whole process of the metadata creation using our _wollmilchsau_ test collection as an example.

We will use repo-ingestion@acdh-cluster as the runtime environment
(so for starters please obtain a console in the arche-ingestion@acdh-cluster - see instructions [here](https://github.com/acdh-oeaw/arche-ingest/blob/master/docs/acdh-cluster.md)).

1. Put collection data into `/ARCHE/staging/testWollmilchsau`:
   ```bash
   mkdir /ARCHE/staging/testWollmilchsau
   cp -R /repo-userstories/wollmilchsau_9552/import_var2/data /ARCHE/staging/testWollmilchsau/data
   ```
2. Run the [repo-filechecker](https://github.com/acdh-oeaw/repo-file-checker) on the data
   ```bash
   mkdir /ARCHE/staging/testWollmilchsau/checkReports
   /ARCHE/vendor/bin/arche-filechecker \
     --overwrite \
     /ARCHE/staging/testWollmilchsau/data \
     /ARCHE/staging/testWollmilchsau/checkReports
   ```
3. Create the directory for the metadata and make a first metadata crawler run
   just on the filechecker output:
   ```bash
   mkdir -p /ARCHE/staging/testWollmilchsau/metadata/input
   /ARCHE/vendor/bin/arche-crawl-meta \
     --filecheckerReportDir /ARCHE/staging/testWollmilchsau/checkReports \
     /ARCHE/staging/testWollmilchsau/metadata/input \
     /ARCHE/staging/testWollmilchsau/metadata/metadata.ttl \
     /ARCHE/staging/testWollmilchsau/data \
     https://id.acdh.oeaw.ac.at/wollmilchsau
   ```
   which will result in something like:
   ```
   2023-11-16 11:16:18.737315	info	----------------------------------------
   2023-11-16 11:16:18.737452	info	Reading and merging metadata
   2023-11-16 11:16:18.737467	info	----------------------------------------
   2023-11-16 11:16:18.739269	info	Impoting filechecker output file /ARCHE/staging/testWollmilchsau/metadata/input/fileList.json
   2023-11-16 11:16:18.743590	info		Data on 20 files and directories imported
   2023-11-16 11:16:18.748418	info	----------------------------------------
   2023-11-16 11:16:18.748455	info	Checking merged metadata
   2023-11-16 11:16:18.748466	info	----------------------------------------
   2023-11-16 11:16:18.749818	error	https://id.acdh.oeaw.ac.at/wollmilchsau errors: 
   Array
   (
       [0] => required property https://vocabs.acdh.oeaw.ac.at/schema#hasTitle is missing
   (...)
       [9] => required property https://vocabs.acdh.oeaw.ac.at/schema#hasHosting is missing
   )
   2023-11-16 11:16:18.755033	info	----------------------------------------
   2023-11-16 11:16:18.755043	info	Saving the output
   2023-11-16 11:16:18.755051	info	----------------------------------------
   2023-11-16 11:16:18.765592	info	Output written to /ARCHE/staging/testWollmilchsau/metadata/metadata.ttl
   ```
   As we can see the filechecker output file has been parsed and contained
   description of 20 files and directories.
   There was no other metadata source (not surprisingly) so the metadata crawler
   passed on to checks revealing (again not surprisingly) a lot of metadata
   properties to be missing.
   Finally the metadata created based on the available input has been saved to
   the `/ARCHE/staging/testWollmilchsau/metadata/metadata.ttl` which looks
   as follows:
   ```
   @prefix acdh: <https://vocabs.acdh.oeaw.ac.at/schema#>.
   
   @prefix acdhi: <https://id.acdh.oeaw.ac.at/>.
   
   @prefix rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>.
   
   acdhi:wollmilchsau a acdh:TopCollection;
       acdh:hasIdentifier acdhi:wollmilchsau.
   <https://id.acdh.oeaw.ac.at/wollmilchsau/3d> a acdh:Collection;
       acdh:hasIdentifier <https://id.acdh.oeaw.ac.at/wollmilchsau/3d>.
   <https://id.acdh.oeaw.ac.at/wollmilchsau/3d/AT-OeAW-BA-3-27-A-GL1767.nxs> a acdh:Resource;
       acdh:hasFilename "AT-OeAW-BA-3-27-A-GL1767.nxs";
       acdh:hasFormat "application/octet-stream";
       acdh:hasIdentifier <https://id.acdh.oeaw.ac.at/wollmilchsau/3d/AT-OeAW-BA-3-27-A-GL1767.nxs>;
       acdh:hasRawBinarySize "7727872"^^<http://www.w3.org/2001/XMLSchema#integer>.
   (...)
   ```
   and contains information which can be derived from the filechecker output.  
   Remarks:
   * We created the `/ARCHE/staging/testWollmilchsau/metadata/input` subdirectory
     because we are writing the merged metadata to `/ARCHE/staging/testWollmilchsau/metadata/metadata.ttl`
     and we do not want it to be picked up as an input on the next metadata crawler run.
     So to separate the input metadata from the merged metadata we put them
     into separate directories.
4. Prepare metadata templates for the top collection, collection(s) and named entities:
   ```bash
   /ARCHE/vendor/bin/arche-create-metadata-template \
     /ARCHE/staging/testWollmilchsau/metadata/input \
     all
   ```
   which creates `TopCollection.xlsx`, `Collection.xlsx` and `NamedEntitied.xlsx`
   in the `/ARCHE/staging/testWollmilchsau/metadata/input` directory.
5. Fill in the metadata templates and provide additional metadata either in
   RDF metadata file(s) or vertical metadata file(s) (or both).
   The sample data can be found [here](wollmilchsau)
6. Run the metadata crawler once more:
   ```bash
   /ARCHE/vendor/bin/arche-crawl-meta \
     --filecheckerReportDir /ARCHE/staging/testWollmilchsau/checkReports \
     /ARCHE/staging/testWollmilchsau/metadata/input \
     /ARCHE/staging/testWollmilchsau/metadata/metadata.ttl \
     /ARCHE/staging/testWollmilchsau/data \
     https://id.acdh.oeaw.ac.at/wollmilchsau
   ```
