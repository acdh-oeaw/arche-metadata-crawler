<?php

/*
 * The MIT License
 *
 * Copyright 2023 zozlak.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace acdhOeaw\arche\metadataCrawler;

use DirectoryIterator;
use Generator;
use SplFileInfo;
use Psr\Log\LoggerInterface;
use quickRdf\DatasetNode;
use quickRdf\Dataset;
use rdfInterface\DatasetInterface;
use rdfInterface\DatasetNodeInterface;
use quickRdf\DataFactory as DF;
use quickRdf\Quad;
use quickRdf\NamedNode;
use termTemplates\PredicateTemplate as PT;
use termTemplates\QuadTemplate as QT;
use acdhOeaw\arche\lib\schema\Ontology;
use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\lib\ingest\util\FileId;
use zozlak\RdfConstants as RDF;

/**
 * Description of DirectoryCrawler
 *
 * @author zozlak
 */
class DirectoryCrawler {

    private const FILE_DEFAULT_CLASS     = 'https://vocabs.acdh.oeaw.ac.at/schema#Resource';
    private const SPREADSHEET_EXTENSIONS = ['csv', 'xls', 'xlsx', 'ods'];

    private Ontology $ontology;
    private Schema $schema;
    private string $idPrefix;
    private LoggerInterface | null $log;
    private EntitiesDatabase $entitiesDb;
    private array $metaStack;
    private FileId $idgen;
    private NamedNode $idProp;

    public function __construct(Ontology $ontology, Schema $schema,
                                string $idPrefix,
                                LoggerInterface | null $log = null) {
        if (substr($idPrefix, -1) !== '/') {
            $idPrefix .= '/';
        }
        $this->ontology   = $ontology;
        $this->schema     = $schema;
        $this->idPrefix   = $idPrefix;
        $this->log        = $log;
        $this->idProp     = DF::namedNode($this->schema->id);
        $this->entitiesDb = new EntitiesDatabase($ontology, $schema, $log);
        $this->metaStack  = [new Dataset()];
    }

    public function parseFilecheckerOutput(string $path, string $basePath = ''): void {
        $typeProp = DF::namedNode(RDF::RDF_TYPE);
        $this->log?->info("Impoting filechecker output file $path");
        if (!empty($basePath) && substr($basePath, -1) !== '/') {
            $basePath .= '/';
        }
        $idgen = new FileId($this->idPrefix, $basePath);
        $data  = json_decode(file_get_contents($path));
        $n     = 0;
        $meta  = $this->metaStack[0];
        foreach ($data as $i) {
            if (!str_starts_with($i->directory, $basePath)) {
                $this->log?->warning("Skipping $i->directory/$i->filename because it's out of the base path ($basePath)");
                continue;
            }
            $iPath = $i->directory . (!empty($i->directory) && substr($i->directory, -1) !== '/' ? '/' : '') . $i->filename;
            if (substr($iPath, -1) === '/') {
                $iPath = substr($iPath, 0, -1);
            }
            $id = DF::namedNode($idgen->getId($iPath));
            $meta->add(DF::quad($id, $this->schema->fileName, DF::literal($i->filename)));
            $meta->add(DF::quad($id, $this->schema->binarySize, DF::literal($i->size)));
            $meta->add(DF::quad($id, $this->schema->mime, DF::literal($i->mime)));
            $meta->add(DF::quad($id, $typeProp, DF::namedNode($i->class ?? self::FILE_DEFAULT_CLASS)));
            $n++;
        }
        $this->log?->info("\tData on $n files imported");
    }

    public function crawl(string $path): DatasetInterface {
        $this->idgen = new FileId($this->idPrefix, $path);
        $meta        = new Dataset();
        $this->crawlDir(new SplFileInfo($path), $meta);
        return $meta;
    }

    private function crawlDir(SplFileInfo $path, Dataset $meta): void {
        $iter    = new DirectoryIterator($path);
        $special = $dirs    = $files   = [];
        foreach ($iter as $i) {
            if ($i->isDot()) {
                continue;
            }
            $i = $i->getFileInfo();
            if ($i->isDir()) {
                $dirs[] = $i;
            } elseif (str_starts_with($i->getFilename(), '__')) {
                $special[] = $i;
            } else {
                $files[] = $i;
            }
        }
        $dirMeta = new Dataset();
        foreach ($special as $i) {
            if (in_array(strtolower($i->getExtension()), self::SPREADSHEET_EXTENSIONS)) {
                $entityList = new EntityListWorksheet($i->getPathname(), $this->ontology, $this->schema, EntityListWorksheet::STRICT_REQUIRED, $this->log);
                $this->entitiesDb->add($entityList->readEntities());

                $dirMeta->add(new MetadataVertical($i->getPathname(), $this->ontology, $this->schema, $this->idPrefix, $this->log));
                $dirMeta->add(new MetadataHorizontal($i->getPathname(), $this->ontology, $this->schema, $this->log));
            } else {
                $dirMeta->add(new MetadataRdf($i->getPathname(), $this->log));
            }
        }
        $this->metaStack[] = $dirMeta;

        $this->debug($path->getPathname());
        $meta->add($this->getMetadata($path));
        foreach ($dirs as $i) {
            $this->crawlDir($i, $meta);
        }
        foreach ($files as $i) {
            $meta->add($this->getMetadata($i));
        }
        array_pop($this->metaStack);
    }

    private function debug($path): void {
        echo "----------\n" . $path . "\n";
        echo "entitiesDb: ";
        $this->entitiesDb->debug();
        echo "metaStack:\n";
        for ($i = count($this->metaStack) - 1; $i >= 0; $i--) {
            echo "\tlevel $i:\n" . $this->metaStack[$i];
        }
        echo "\n";
    }

    /**
     * 
     * @param SplFileInfo $i
     * @return Generator<Quad>
     */
    private function getMetadata(SplFileInfo $path): Generator {
        $sbj       = DF::namedNode($this->idgen->getId($path));
        if (preg_match('`__`', $sbj->getValue())) {
            print_r($path);
            exit();
        }
        $allTmpl   = new QT(DF::namedNode(RDF::OWL_THING));
        $fileTmpl  = new QT($sbj);
        $classTmpl = $fileTmpl->withPredicate(DF::namedNode(RDF::RDF_TYPE));
        foreach ($this->metaStack as $i) {
            /* @var $i Dataset */
            $meta = $i->map(fn(Quad $x) => $x->withSubject($sbj), $allTmpl);
            $meta->add($i->copy($fileTmpl));
            foreach ($meta->listObjects($classTmpl) as $class) {
                $meta->add($i->map(fn($x) => $x->withSubject($sbj), new QT($class)));
            }
            $meta->add(DF::quad($sbj, $this->idProp, $sbj));
            yield from $meta;
        }
    }
}
