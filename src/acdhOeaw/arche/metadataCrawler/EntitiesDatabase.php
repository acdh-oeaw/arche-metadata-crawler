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

use rdfInterface\DatasetNodeInterface;
use quickRdf\DataFactory as DF;
use termTemplates\PredicateTemplate as PT;
use acdhOeaw\arche\lib\schema\Ontology;
use acdhOeaw\arche\lib\Schema;
use zozlak\RdfConstants as RDF;
use Psr\Log\LoggerInterface;

/**
 * Description of EntitiesDatabase
 *
 * @author zozlak
 */
class EntitiesDatabase {

    private Ontology $ontology;
    private Schema $schema;
    private LoggerInterface | null $log;

    /**
     * 
     * @var array<string, DateasetNodeInterface>
     */
    private array $byId = [];

    /**
     * 
     * @var array<string, array<string, DatasetNodeInterface>>
     */
    private array $byClass = [];

    public function __construct(Ontology $ontology, Schema $schema,
                                LoggerInterface | null $log = null) {
        $this->ontology = $ontology;
        $this->schema   = $schema;
        $this->log      = $log;
    }

    /**
     * 
     * @param iterable<DatasetNodeInterface> $entities
     * @return void
     */
    public function add(iterable $entities): void {
        $nmsp      = $this->ontology->getNamespace();
        $idProp    = (string) $this->schema->id;
        $labelProp = (string) $this->schema->label;
        $classTmpl = new PT(DF::namedNode(RDF::RDF_TYPE));
        $labelTmpl = new PT(DF::namedNode($labelProp));
        $idTmpl    = new PT(DF::namedNode($idProp));
        foreach ($entities as $entity) {
            /* @var $entity DatasetNodeInterface */
            $classes = $entity->listObjects($classTmpl)->getValues();
            $labels  = $entity->listObjects($labelTmpl)->getValues();
            foreach ($classes as $class) {
                foreach ($labels as $label) {
                    if (isset($this->entities[$class][$label])) {
                        $this->log?->warning("overwritting mapping for $class and $label");
                    }
                    $this->entities[$class][$label] = $entity;
                }
            }
            foreach ($entity->listObjects($idTmpl)->getValues() as $id) {
                $this->byId[$id] = $entity;
            }
        }
    }

    public function exists(string $id, string | null $class = null): bool {
        return $class === null ? isset($this->byId[$id]) : isset($this->byClass[$class][$id]);
    }

    public function get(string $id, string | null $class = null): DatasetNodeInterface | null {
        return $class === null ? $this->byId[$id] ?? null : $this->byClass[$class][$id] ?? null;
    }

    public function getId(string $id, string | null $class = null): string | null {
        $entity = $this->get($id, $class);
        if ($entity === null) {
            return null;
        }
        return $entity->listObjects(new PT($this->schema->id))->current();
    }
}
