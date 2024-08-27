<?php

/*
 * The MIT License
 *
 * Copyright 2023 Austrian Centre for Digital Humanities.
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
use SplObjectStorage;
use Psr\Log\LoggerInterface;
use quickRdf\Dataset;
use rdfInterface\DatasetInterface;
use rdfInterface\NamedNodeInterface;
use rdfInterface\TermInterface;
use quickRdf\DataFactory as DF;
use quickRdf\Quad;
use quickRdf\NamedNode;
use rdfHelpers\DefaultGraph;
use termTemplates\QuadTemplate as QT;
use termTemplates\PredicateTemplate as PT;
use acdhOeaw\arche\lib\schema\Ontology;
use acdhOeaw\arche\lib\schema\PropertyDesc;
use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\lib\ingest\util\FileId;
use zozlak\RdfConstants as RDF;

/**
 * Description of DirectoryCrawler
 *
 * @author zozlak
 */
class MetadataCrawler {

    private const FILECHECKER_FILE       = 'fileList.json';
    private const FILE_DEFAULT_CLASS     = 'resource';
    private const SPREADSHEET_EXTENSIONS = ['csv', 'xls', 'xlsx', 'ods'];

    private Ontology $ontology;
    private Schema $schema;
    private string $idPrefix;
    private string $defaultLang;
    private LoggerInterface | null $log;
    private EntitiesDatabase $entitiesDb;
    private FileId $idgen;
    private NamedNode $idProp;
    private array $files;
    private Dataset $metaPrimary;
    private Dataset $metaSecondary;

    public function __construct(string $metaDir, Ontology $ontology,
                                Schema $schema, string $idPrefix,
                                string $filecheckerBaseDir, string $defaultLang,
                                LoggerInterface | null $log = null) {
        if (substr($idPrefix, -1) !== '/') {
            $idPrefix .= '/';
        }
        $this->ontology      = $ontology;
        $this->schema        = $schema;
        $this->idPrefix      = $idPrefix;
        $this->defaultLang   = $defaultLang;
        $this->log           = $log;
        $this->idProp        = DF::namedNode($this->schema->id);
        $this->entitiesDb    = new EntitiesDatabase($ontology, $schema, $log);
        $this->metaPrimary   = new Dataset();
        $this->metaSecondary = new Dataset();

        $this->readMetadata($metaDir, $filecheckerBaseDir);
    }

    private function readMetadata(string $metaDir, string $filecheckerBaseDir): void {
        $filecheckerFile = $metaDir . '/' . self::FILECHECKER_FILE;
        if (!file_exists($filecheckerFile)) {
            throw new MetadataCrawlerException("Filechecker output file - $metaDir/" . self::FILECHECKER_FILE . " - doesn't exist");
        }
        $baseGraph = $this->parseFilecheckerOutput($filecheckerFile, $filecheckerBaseDir);

        foreach (new DirectoryIterator($metaDir) as $i) {
            if ($i->isDot() || str_contains($i->getFileInfo(), '~')) {
                continue;
            }
            if (in_array(strtolower($i->getExtension()), self::SPREADSHEET_EXTENSIONS)) {
                $entityList = new EntityListWorksheet($i->getPathname(), $this->ontology, $this->schema, $this->defaultLang, EntityListWorksheet::STRICT_REQUIRED, $this->log);
                $n          = $this->entitiesDb->add($entityList->readEntities());
                $n          = $n || $this->addMetaSecondary(new MetadataVertical($i->getPathname(), $this->ontology, $this->schema, $this->idPrefix, $this->defaultLang, $this->log));
                $n          = $n || $this->addMetaSecondary(new MetadataHorizontal($i->getPathname(), $this->ontology, $this->schema, $this->idPrefix, $this->defaultLang, $this->log));
                if (!$n) {
                    $this->log?->warning("Failed to parse " . $i->getPathname() . " in all supported formats");
                }
            } elseif ($i->getFilename() !== self::FILECHECKER_FILE) {
                try {
                    $this->metaSecondary->add(new MetadataRdf($i->getPathname(), $this->schema->id, $this->log));
                } catch (\Exception $e) {
                    $this->log?->warning("Failed to parse " . $i->getPathname() . " as an RDF file");
                }
            }
        }

        $dg         = new DefaultGraph();
        $this->metaSecondary->forEach(fn(Quad $x) => $dg->equals($x->getGraph()) ? $x->withGraph($baseGraph) : $x);
        // map named entity labels
        $entitiesDb = $this->entitiesDb;
        $this->metaSecondary->forEach(function (Quad $q) use ($entitiesDb) {
            $obj    = $q->getObject();
            $objStr = (string) $obj;
            if ($obj instanceof NamedNodeInterface && !str_starts_with($objStr, 'http')) {
                $id = $entitiesDb->getId($objStr);
                if ($id) {
                    return $q->withObject(DF::namedNode($id));
                }
            }
            return $q;
        });
        
        $this->mapVocabularies($this->metaPrimary);
        $this->mapVocabularies($this->metaSecondary);
    }

    /**
     * 
     * @param iterable<Quad> $meta
     * @return int
     */
    private function addMetaSecondary(iterable $meta): int {
        $checked = new SplObjectStorage;
        foreach ($meta as $quad) {
            /* @var $quad Quad */
            $sbj = $quad->getSubject();
            if ($checked->contains($sbj)) {
                $this->metaSecondary->add($quad);
            } else {
                if ($this->metaPrimary->any(new QT($quad->getSubject()))) {
                    $this->metaSecondary->add($quad);
                } else {
                    $this->log?->warning("\t$sbj is missing in the filechecker output");
                }
                $checked->attach($sbj);
            }
        }
        return count($checked);
    }

    private function parseFilecheckerOutput(string $path, string $basePath): NamedNode {
        $this->log?->info("Impoting filechecker output file $path");
        if (!empty($basePath) && substr($basePath, -1) !== '/') {
            $basePath .= '/';
        }

        $typeProp  = DF::namedNode(RDF::RDF_TYPE);
        $idgen     = new FileId($this->idPrefix, $basePath);
        $classDict = $this->schema->classes;

        $bid = DF::namedNode($idgen->getId($basePath));
        $this->metaPrimary->add(DF::quad($bid, $typeProp, $classDict->topCollection));
        $this->metaPrimary->add(DF::quad($bid, $this->schema->id, $bid));
        $n   = 1;

        $dirs = [];
        foreach (json_decode(file_get_contents($path)) as $i) {
            if (!str_starts_with($i->directory . '/', $basePath)) {
                $this->log?->warning("Skipping $i->directory/$i->filename because it's out of the base path ($basePath)");
                continue;
            }
            $iPath = $i->directory . (!empty($i->directory) && substr($i->directory, -1) !== '/' ? '/' : '') . $i->filename;
            if (substr($iPath, -1) === '/') {
                $iPath = substr($iPath, 0, -1);
            }
            $iDir = dirname($iPath);
            while (!isset($dirs[$iDir]) && $iDir . '/' !== $basePath) {
                $dirs[$iDir] = 1;
                $id          = DF::namedNode($idgen->getId($iDir));
                $this->metaPrimary->add(DF::quad($id, $typeProp, $classDict->collection));
                $this->metaPrimary->add(DF::quad($id, $this->schema->id, $id));
                $this->metaPrimary->add(DF::quad($id, $this->schema->parent, DF::namedNode($idgen->getId(dirname($iDir)))));
                $this->metaPrimary->add(DF::quad($id, $this->schema->fileName, DF::literal(basename($iDir))));
                $n++;
                $iDir        = dirname($iDir);
            }
            $id = DF::namedNode($idgen->getId($iPath));
            $this->metaPrimary->add(DF::quad($id, $typeProp, DF::namedNode($i->class ?? $classDict->{self::FILE_DEFAULT_CLASS})));
            $this->metaPrimary->add(DF::quad($id, $this->schema->id, $id));
            $this->metaPrimary->add(DF::quad($id, $this->schema->parent, DF::namedNode($idgen->getId(dirname($iPath)))));
            $this->metaPrimary->add(DF::quad($id, $this->schema->fileName, DF::literal($i->filename)));
            // Seta asked not to map the binary size (2024-06-28)
            //$this->metaPrimary->add(DF::quad($id, $this->schema->binarySize, DF::literal($i->size)));
            if (!empty($i->mime)) {
                $this->metaPrimary->add(DF::quad($id, $this->schema->mime, DF::literal($i->mime)));
            }
            if (!empty($i->hasCategory)) {
                $this->metaPrimary->add(DF::quad($id, $this->schema->category, DF::namedNode($i->hasCategory)));
            }
            $n++;
        }
        $this->log?->info("\tData on $n files and directories imported");

        return $bid;
    }

    public function crawl(): DatasetInterface {
        $graphs = iterator_to_array($this->metaSecondary->listGraphs());
        usort($graphs, fn(TermInterface $x, TermInterface $y) => -1 * ($x->getValue() <=> $y->getValue()));

        $classTmpl    = new PT(DF::namedNode(RDF::RDF_TYPE));
        $idTmpl       = new PT($this->idProp);
        $thingTmpl    = new QT(DF::namedNode(RDF::OWL_THING));
        $labelTmpl    = new PT($this->schema->label);
        $filenameTmpl = new PT($this->schema->fileName);
        $meta         = new Dataset();
        foreach ($this->metaPrimary->listSubjects() as $sbj) {
            $metaTmp = $this->metaPrimary->copy(new QT($sbj));
            $ids     = $metaTmp->listObjects($idTmpl);
            $classes = $metaTmp->listObjects($classTmpl);

            $addedProps = new SplObjectStorage();
            foreach ($ids as $id) {
                foreach ($this->metaSecondary->getIterator(new QT($id)) as $quad) {
                    $metaTmp->add($quad);
                    $addedProps->attach($quad->getPredicate());
                }
            }
            foreach ($classes as $class) {
                foreach (array_filter($graphs, fn($x) => str_starts_with($sbj->getValue(), $x->getValue())) as $graph) {
                    $propsToAdd = [];
                    foreach ($this->metaSecondary->getIterator(new QT($class, graph: $graph)) as $quad) {
                        if (!$addedProps->contains($quad->getPredicate())) {
                            $metaTmp->add($quad->withSubject($sbj));
                            $propsToAdd[] = $quad->getPredicate();
                        }
                    }
                    array_map(fn($x) => $addedProps->attach($x), $propsToAdd);
                }
            }
            foreach ($graphs as $graph) {
                foreach ($this->metaSecondary->getIterator($thingTmpl->withGraph($graph)) as $quad) {
                    if (!$addedProps->contains($quad->getPredicate())) {
                        $metaTmp->add($quad->withSubject($sbj));
                    }
                }
            }
            if ($metaTmp->none($labelTmpl)) {
                $metaTmp->add($metaTmp->map(fn(Quad $x) => $x->withPredicate($this->schema->label)->withObject($x->getObject()->withLang('und')), $filenameTmpl));
            }
            $meta->add($metaTmp);
        }

        $sortedMeta = new Dataset();
        // add projects and publications to $meta so all object triple values are mapped
        foreach ($this->entitiesDb->getEntitiesOfClass($this->schema->classes->project) as $i) {
            $meta->add($i);
        }
        foreach ($this->entitiesDb->getEntitiesOfClass($this->schema->classes->publication) as $i) {
            $meta->add($i);
        }
        $objects = $meta->listObjects(
            function (Quad $x) {
                $prop     = $x->getPredicate();
                $propDesc = $this->ontology->getProperty(null, (string) $prop);
                return !$prop->equals($this->idProp) && $propDesc?->type === RDF::OWL_OBJECT_PROPERTY && empty($propDesc?->vocabs);
            }
        );
        foreach ($objects as $obj) {
            $entityMeta = $this->entitiesDb->get($obj->getValue());
            if ($entityMeta !== null) {
                if (!$obj->equals($entityMeta->getNode())) {
                    $entityUri = $entityMeta->getNode();
                    $meta->forEach(fn(Quad $x) => $x->withObject($entityUri), new QT(object: $obj));
                }
                $sortedMeta->add($entityMeta);
            } else {
                //echo "did not find mapping for $obj\n";
            }
            // any other entity meta collected from metadata input
            $tmpl = new QT($obj);
            $sortedMeta->add($this->metaPrimary->getIterator($tmpl));
            $sortedMeta->add($this->metaSecondary->getIterator($tmpl));
        }
        // now add them to $sortedMeta so they are placed after persons/organizations/places
        // (they will be added once again from $meta below with no effect as they will exist with $sortedMeta already)
        foreach ($this->entitiesDb->getEntitiesOfClass($this->schema->classes->project) as $i) {
            $sortedMeta->add($i);
        }
        foreach ($this->entitiesDb->getEntitiesOfClass($this->schema->classes->publication) as $i) {
            $sortedMeta->add($i);
        }

        $subjects = iterator_to_array($meta->listSubjects());
        usort($subjects, fn($a, $b) => $a->getValue() <=> $b->getValue());
        foreach ($subjects as $sbj) {
            $props = iterator_to_array($meta->listPredicates(new QT($sbj)));
            usort($props, fn($a, $b) => $a->getValue() <=> $b->getValue());
            foreach ($props as $prop) {
                $sortedMeta->add($meta->getIterator(new QT($sbj, $prop)));
            }
        }
        return $sortedMeta;
    }

    /**
     * Custom implementation because Ontology::getVocabularyValue()
     * has no caching.
     * 
     * @param Dataset $meta
     * @param Quad $x
     * @return void
     */
    private function mapVocabularies(Dataset $meta): void {
        foreach ($this->ontology->getProperties() as $propDesc) {
            /* @var $propDesc PropertyDesc */
            if (!empty($propDesc->vocabs)) {
                $values = [];
                foreach ($propDesc->vocabularyValues as $concept) {
                    foreach ($concept->concept as $id) {
                        $values[$id] = $concept->uri;
                    }
                    foreach ($concept->label as $id) {
                        $values[$id] = $concept->uri;
                    }
                    foreach ($concept->notation as $id) {
                        $values[$id] = $concept->uri;
                    }
                }
                $mapper = fn(Quad $x) => $x->withObject(DF::namedNode($values[(string) $x->getObject()] ?? $x->getObject()));
                $meta->forEach($mapper, new PT($propDesc->uri));
            }
        }
    }
}
