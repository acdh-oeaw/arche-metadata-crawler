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

use rdfInterface\DatasetNodeInterface;
use quickRdf\DataFactory as DF;
use quickRdf\NamedNode;
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

    /** @phpstan-ignore property.onlyWritten */
    private Ontology $ontology;
    private Schema $schema;
    private LoggerInterface | null $log;

    /**
     * 
     * @var array<string, DatasetNodeInterface>
     */
    private array $byId = [];

    /**
     * 
     * @var array<string, array<string, DatasetNodeInterface|null>>
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
     * @return int number of added entities
     */
    public function add(iterable $entities): int {
        $idProp    = (string) $this->schema->id;
        $labelProp = (string) $this->schema->label;
        $classTmpl = new PT(DF::namedNode(RDF::RDF_TYPE));
        $labelTmpl = new PT(DF::namedNode($labelProp));
        $idTmpl    = new PT(DF::namedNode($idProp));
        $n         = 0;
        foreach ($entities as $entity) {
            /* @var $entity DatasetNodeInterface */
            $classes = $entity->listObjects($classTmpl)->getValues();
            $labels  = $entity->listObjects($labelTmpl)->getValues();
            foreach ($classes as $class) {
                if (!isset($this->byClass[$class])) {
                    $this->byClass[$class] = [];
                }
                foreach ($labels as $label) {
                    $this->checkAndMap($entity, $label, $this->byClass[$class], "($class class mapping)");
                }
            }
            foreach ($entity->listObjects($idTmpl)->getValues() as $id) {
                $this->checkAndMap($entity, $id, $this->byId, '(global mapping)');
            }
            foreach ($labels as $label) {
                $this->checkAndMap($entity, $label, $this->byId, '(global mapping)');
            }
            $n++;
        }
        return $n;
    }

    /**
     * 
     * @param DatasetNodeInterface $entity
     * @param string $key
     * @param array<DatasetNodeInterface|null> $map
     * @return void
     */
    private function checkAndMap(DatasetNodeInterface $entity, string $key,
                                 array &$map, string $msg = ''): void {
        $keyExists = array_key_exists($key, $map);
        $keySet    = isset($map[$key]);
        if (!$keyExists) {
            $map[$key] = $entity;
        } elseif (!$keySet) {
            $this->log?->warning("\t\t\tduplication for key '$key' - skipping the mapping $msg");
        } elseif ($map[$key]->getNode()->equals($entity->getNode())) {
            if ($map[$key] !== $entity) {
                $this->log?->warning("\t\t\toverwriting metadata for key '$key' $msg");
            }
        } else {
            $this->log?->warning("\t\t\tduplication for key '$key' - removing the mapping $msg");
            $map[$key] = null;
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
        return $entity->getObject(new PT($this->schema->id));
    }

    /**
     * 
     * @param string $class
     * @return array<DatasetNodeInterface>
     */
    public function getEntitiesOfClass(string $class): array {
        return array_unique(array_filter($this->byClass[$class] ?? [], fn($x) => $x !== null), SORT_REGULAR);
    }

    /**
     * 
     * @return array<NamedNode>
     */
    public function getClasses(): array {
        return array_map(fn($x) => DF::namedNode($x), array_keys($this->byClass));
    }
}
