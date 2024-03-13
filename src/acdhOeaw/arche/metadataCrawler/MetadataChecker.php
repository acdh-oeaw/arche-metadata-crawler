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

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use quickRdf\Dataset;
use rdfInterface\DatasetInterface;
use quickRdf\DataFactory as DF;
use quickRdf\Literal;
use quickRdf\NamedNode;
use termTemplates\QuadTemplate as QT;
use termTemplates\PredicateTemplate as PT;
use termTemplates\NotTemplate as NT;
use termTemplates\AnyOfTemplate as AT;
use acdhOeaw\arche\lib\schema\Ontology;
use acdhOeaw\arche\lib\schema\ClassDesc;
use acdhOeaw\arche\lib\schema\PropertyDesc;
use acdhOeaw\arche\lib\Schema;
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
    private array $normalizers;
    private array $checkRanges;
    private array $vocabularies;
    private Dataset $meta;

    public function __construct(Ontology $ontology, Schema $schema,
                                LoggerInterface | null $log = null) {
        $this->ontology = $ontology;
        $this->schema   = $schema;
        $this->log      = $log;

        $this->checkRanges = [];
        foreach ($this->schema->checkRanges ?? [] as $range => $nmsps) {
            $this->checkRanges[$range] = array_map(fn($x) => (string) $x, iterator_to_array($nmsps));
        }

        $client            = new Client();
        $this->normalizers = [
            '' => new UriNormalizer(),
        ];
        foreach ($schema->checkRanges as $class => $ranges) {
            $rules                     = UriNormRules::getRules(array_map(fn($x) => (string) $x, iterator_to_array($ranges)));
            $this->normalizers[$class] = new UriNormalizer($rules, '', $client);
        }

        foreach ($this->ontology->getProperties() as $propDesc) {
            if (!empty($propDesc->vocabs)) {
                $tmp = [];
                foreach ($propDesc->vocabularyValues as $concept) {
                    foreach ($concept->concept as $id) {
                        $tmp[$id] = $concept->uri;
                    }
                }
                $this->vocabularies[$propDesc->uri] = $tmp;
            }
        }
    }

    public function check(DatasetInterface $meta): bool {
        $this->meta = $meta;
        $classTmpl  = new PT(DF::namedNode(RDF::RDF_TYPE));
        $noErrors   = true;
        foreach ($this->meta->listSubjects() as $sbj) {
            $errors = [];

            $sbjMeta    = $meta->copy(new QT($sbj));
            $sbjClasses = $sbjMeta->listObjects($classTmpl)->getValues();
            if (count($sbjClasses) === 0) {
                $errors[] = "rdf:type property missing";
            } elseif (count($sbjClasses) > 1) {
                $errors[] = "multiple rdf:types: " . implode(', ', $sbjClasses);
            } else {
                foreach ($sbjClasses as $class) {
                    $classDesc = $this->ontology->getClass($class);
                    $this->checkClass($sbjMeta, $classDesc, $errors);
                }
            }

            if (count($errors) > 0) {
                $noErrors = false;
                $this->log?->error("$sbj errors: \n" . print_r($errors, true));
            }
        }
        return $noErrors;
    }

    public function checkClass(DatasetInterface $sbjMeta,
                               ClassDesc | string $class, array &$errors): void {
        $classDesc     = $class instanceof ClassDesc ? $class : $this->ontology->getClass($class);
        $sbjProperties = $sbjMeta->listPredicates()->getValues();
        $sbjProperties = array_filter($sbjProperties, fn($x) => (string) $x !== RDF::RDF_TYPE);

        // required by class
        $missing = array_filter(
            $classDesc->getProperties(),
            fn(PropertyDesc $x) => $x->min > 0 && !$x->automatedFill && count(array_intersect($x->property, $sbjProperties)) === 0
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
                if ($propDesc->uri === $this->schema->id) {
                    $this->checkNamedEntity($value, false, $propDesc, $errors);
                } elseif (!empty($propDesc->vocabs)) {
                    if (!isset($this->vocabularies[$propDesc->uri][(string) $value])) {
                        $errors[] = "$propDesc->uri value $value does not match the controlled vocabulary";
                    }
                } elseif (count(array_intersect($propDesc->range, array_keys($this->checkRanges))) > 0) {
                    $this->checkNamedEntity($value, true, $propDesc, $errors);
                }
            }
        }
    }

    private function checkNamedEntity(NamedNode $value, bool $resolve,
                                      PropertyDesc $propDesc, array &$errors): void {
        static $tmpl = null;
        $tmpl        ??= new QT(null, new NT(new AT([$this->schema->id, $this->schema->label,
                    DF::namedNode(RDF::RDF_TYPE)])));
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
                $errors[] = $propDesc->uri . ' value ' . $ex->getMessage();
            }
        }
    }
}
