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

use Psr\Log\LoggerInterface;
use zozlak\ProxyClient;
use rdfInterface\DatasetInterface;
use rdfInterface\NamedNodeInterface;
use quickRdf\DataFactory as DF;
use quickRdf\Literal;
use quickRdf\NamedNode;
use quickRdf\DatasetNode;
use termTemplates\QuadTemplate as QT;
use termTemplates\PredicateTemplate as PT;
use termTemplates\NotTemplate as NT;
use termTemplates\AnyOfTemplate as AT;
use acdhOeaw\arche\lib\schema\Ontology;
use acdhOeaw\arche\lib\schema\ClassDesc;
use acdhOeaw\arche\lib\schema\PropertyDesc;
use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\doorkeeper\DoorkeeperException;
use acdhOeaw\arche\doorkeeper\PreCheckAttribute;
use acdhOeaw\arche\doorkeeper\CheckAttribute;
use acdhOeaw\arche\doorkeeper\Resource as Doorkeeper;
use acdhOeaw\UriNormalizer;
use acdhOeaw\UriNormalizerCache;
use acdhOeaw\UriNormalizerException;
use acdhOeaw\UriNormRules;
use zozlak\RdfConstants as RDF;

/**
 * Description of MetadataChecker
 *
 * @author zozlak
 */
class MetadataChecker {

    private Ontology $ontology;
    private Schema $schema;
    private LoggerInterface | null $log;
    /**
     * 
     * @var array<string, UriNormalizer>
     */
    private array $normalizers;
    /**
     * 
     * @var array<string, array<string>>
     */
    private array $checkRanges;
    /**
     * 
     * @var array<string, array<string, string>>
     */
    private array $vocabularies;
    private DatasetInterface $meta;

    public function __construct(Ontology $ontology, Schema $schema,
                                LoggerInterface | null $log = null) {
        $this->ontology = $ontology;
        $this->schema   = $schema;
        $this->log      = $log;

        $this->checkRanges = [];
        foreach ($this->schema->checkRanges ?? [] as $range => $nmsps) {
            $this->checkRanges[$range] = array_map(fn($x) => (string) $x, iterator_to_array($nmsps));
        }

        $client            = ProxyClient::factory();
        $cache             = new UriNormalizerCache();
        $this->normalizers = [
            '' => new UriNormalizer(cache: $cache),
        ];
        /** @phpstan-ignore property.notFound */
        foreach ($schema->checkRanges as $class => $ranges) {
            $rules                     = UriNormRules::getRules(array_map(fn($x) => (string) $x, iterator_to_array($ranges)));
            $this->normalizers[$class] = new UriNormalizer($rules, '', $client, $cache);
        }

        foreach ($this->ontology->getProperties() as $propDesc) {
            if (!empty($propDesc->vocabs)) {
                $tmp = [];
                /** @phpstan-ignore property.private */
                foreach ($propDesc->vocabularyValues as $concept) {
                    foreach ($concept->concept as $id) {
                        $tmp[$id] = $concept->uri;
                    }
                }
                $this->vocabularies[$propDesc->uri] = $tmp;
            }
        }
    }

    public function check(DatasetInterface $meta, bool $reportProgress = true): bool {
        $this->meta = $meta;
        $classTmpl  = new PT(DF::namedNode(RDF::RDF_TYPE));
        $noErrors   = true;
        $sbjs       = iterator_to_array($this->meta->listSubjects());
        $N          = count($sbjs);
        foreach ($sbjs as $n => $sbj) {
            if ($n % 10 === 0) {
                $msg = "Check progress: $n/$N " . round(100 * $n / $N, 1) . "%";
                $reportProgress ? $this->log?->info($msg) : $this->log?->debug($msg);
            }
            $errors = [];

            $sbjMeta    = $meta->copy(new QT($sbj));
            $sbjClasses = $sbjMeta->listObjects($classTmpl)->getValues();
            if (count($sbjClasses) === 0) {
                $errors[] = "rdf:type property missing";
            } elseif (count($sbjClasses) > 1) {
                $errors[] = "multiple rdf:types: " . implode(', ', $sbjClasses);
            } else {
#                foreach ($sbjClasses as $class) {
#                    $classDesc = $this->ontology->getClass($class);
#                    $this->checkClass($sbjMeta, $classDesc, $errors);
#                }
                $tmp           = new DatasetNode($sbj);
                $doorkeeper    = new Doorkeeper($tmp->withDataset($sbjMeta), $this->schema, $this->ontology, null, $this->log);
                $doorkeeperErr = array_merge(
                    $doorkeeper->runTests(PreCheckAttribute::class, throwException: false),
                    $doorkeeper->runTests(CheckAttribute::class, throwException: false)
                );
                $this->checkForLocalEntities($doorkeeperErr, $meta);
                $doorkeeperErr = array_map(fn($x) => $x->getMessage(), $doorkeeperErr);
                $errors        = array_merge($errors, $doorkeeperErr);
            }

            if (count($errors) > 0) {
                $noErrors = false;
                foreach ($errors as $e) {
                    $this->log?->error("$sbj;$e");
                }
            }
        }
        return $noErrors;
    }

    /**
     * 
     * @param array<DoorkeeperException|string> $errors
     */
    public function checkClass(DatasetInterface $sbjMeta,
                               ClassDesc | string $class, array &$errors): void {
        $classDesc     = $class instanceof ClassDesc ? $class : $this->ontology->getClass($class);
        $sbjProperties = $sbjMeta->listPredicates()->getValues();
        $sbjProperties = array_filter($sbjProperties, fn($x) => (string) $x !== RDF::RDF_TYPE);

        // required by class
        $missing = array_filter(
            $classDesc->getProperties(),
                                      fn(PropertyDesc $x) => $x->min > 0 && !$x->automatedFill && empty($x->defaultValue) && count(array_intersect($x->property, $sbjProperties)) === 0
        );
        foreach ($missing as $i) {
            $errors[] = "required property $i->uri is missing";
        }

        // existing properties
        foreach ($sbjProperties as $sbjProp) {
            if (!isset($classDesc->properties[(string) $sbjProp])) {
                $errors[] = "unknown property $sbjProp used";
                continue;
            }
            /* @var $propDesc PropertyDesc */
            $propDesc  = $classDesc->properties[(string) $sbjProp];
            $sbjValues = $sbjMeta->listObjects(new PT($sbjProp));
            $langs     = [];
            foreach ($sbjValues as $value) {
                $valueType = $value instanceof Literal ? RDF::OWL_DATATYPE_PROPERTY : RDF::OWL_OBJECT_PROPERTY;
                if ($propDesc->type != $valueType) {
                    $errors[] = "wrong type of value for a $valueType property $propDesc->uri";
                    continue;
                }
                if ($propDesc->langTag && $value instanceof Literal) {
                    $lang = (string) $value->getLang();
                    if ($lang === '') {
                        $errors[] = "value $value of property $propDesc->uri misses the language tag";
                    } elseif (isset($langs[$lang]) && $propDesc->max === 1) {
                        $errors[] = "value $value of property $propDesc->uri has duplicated lang tag $lang";
                    }
                    $langs[$lang] = true;
                }
                if ($propDesc->uri === (string) $this->schema->id) {
                    /** @var NamedNodeInterface $value */
                    $this->checkNamedEntity($value, false, $propDesc, $errors);
                } elseif (!empty($propDesc->vocabs)) {
                    if (!isset($this->vocabularies[$propDesc->uri][(string) $value])) {
                        $errors[] = "$propDesc->uri value $value does not match the controlled vocabulary";
                    }
                } elseif (count(array_intersect($propDesc->range, array_keys($this->checkRanges))) > 0) {
                    /** @var NamedNodeInterface $value */
                    $this->checkNamedEntity($value, true, $propDesc, $errors);
                }
            }
        }
    }

    /**
     * Removes "Failed to fetch RDF data from {URI}" errors related to locally defined entities
     * (having a given subject or identifier).
     * 
     * Doesnt' check if entities themselves are valid (but this is checked in the check() method loop)
     * 
     * @param array<DoorkeeperException> $errors
     */
    private function checkForLocalEntities(array &$errors,
                                           DatasetInterface $meta): void {
        for ($i = 0; $i < count($errors); $i++) {
            $error = $errors[$i]->getPrevious();
            if ($error instanceof UriNormalizerException && str_starts_with($error->getMessage(), 'Failed to fetch RDF data from ')) {
                $sbj = DF::namedNode(preg_replace('/ .*/', '', str_replace('Failed to fetch RDF data from ', '', $error->getMessage())));
                if ($meta->any(new QT($sbj)) || $meta->any(new PT($this->schema->id, $sbj))) {
                    $this->log?->debug("Skipping the unresolvable $sbj error because it's defined locally");
                    unset($errors[$i]);
                }
            }
        }
    }

    /**
     * 
     * @param array<DoorkeeperException|string> $errors
     */
    private function checkNamedEntity(NamedNodeInterface $value, bool $resolve,
                                      PropertyDesc $propDesc, array &$errors): void {
        static $tmpl = null;
        $tmpl        ??= new QT(null, new NT(new AT([$this->schema->id, DF::namedNode(RDF::RDF_TYPE)])));
        if ($propDesc->uri === (string) $this->schema->id) {
            $norms = [$this->normalizers['']];
        } else {
            $norms = array_filter($this->normalizers, fn($key) => in_array($key, $propDesc->range), ARRAY_FILTER_USE_KEY);
        }
        foreach ($norms as $norm) {
            try {
                $value = $norm->normalize($value, true);
                if ($resolve) {
                    try {
                        $norm->resolve($value);
                    } catch (UriNormalizerException $ex) {
                        if ($this->meta->none($tmpl->withSubject($value))) {
                            $errors[] = $propDesc->uri . ' value ' . $value . ': ' . $ex->getMessage();
                        } else {
                            $this->log?->debug("Could not resolve $value but it exists as a subject in the output metadata.");
                        }
                    }
                }
            } catch (UriNormalizerException $ex) {
                $errors[] = $propDesc->uri . ' value ' . $value . ': ' . $ex->getMessage();
            }
        }
    }
}
