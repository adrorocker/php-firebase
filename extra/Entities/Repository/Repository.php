<?php
/**
 * PHP-Firebase
 *
 * @link      https://github.com/adrorocker/php-firebase
 * @copyright Copyright (c) 2018 Adro Rocker
 * @author    Adro Rocker <mes@adro.rocks>
 */
namespace PhpFirebase\Entities\Repository;

use PhpFirebase\Entities\Entity;
use PhpFirebase\Entities\EntityInterface;
use PhpFirebase\Firebase;
use PhpFirebase\Clients\GuzzleClient;

abstract class Repository implements RepositoryInterface
{
    protected $class;

    protected $firebase;

    protected $base;

    protected $token;

    protected $model = [];

    protected $endpoint = null;

    protected $query = [];

    public function __construct($base, $token = null, $endpoint = null)
    {
        if ($base instanceof Firebase) {
            $this->firebase = $base;
            $this->endpoint = $token;
        } else {
            $this->base = $base;
            $this->token = $token;

            $this->firebase = new Firebase(
                $this->base,
                $this->token,
                new GuzzleClient(['verify' => false,])
            );

            $this->endpoint = $endpoint;
        }

        if ($this->class == null) {
            $this->class = Entity::class;
        }
    }

    public function store($entity)
    {
        if (is_array($entity)) {
            foreach ($entity as &$record) {
                $id = $record->id() ? $record->id() : guid();
                $record->id($id);
                $this->firebase->put($this->endpoint . '/'. $id, $record->toArray());
                $record = $this->find($id);
            }
        } elseif ($entity instanceof EntityInterface) {
            $id = $entity->id() ? $entity->id() : guid();
            $entity->id($id);
            $response = $this->firebase->put($this->endpoint . '/'. $id, $entity->toArray());
            $entity = $this->find($id);
        }


        return $entity;
    }

    public function find($id)
    {
        $model = (array) $this->firebase->get($this->endpoint . '/'. $id);
        $class = $this->class;
        $this->model = new $class($model);

        return $this->model;
    }

    public function fetch(array $searchCriteria = [])
    {
        $search = array_merge($this->query, $searchCriteria);

        $records = (array) $this->firebase->get($this->endpoint, $search);

        $entities = [];

        $class = $this->class;

        foreach ($records as $record) {
            $record = (array) $record;
            $entity = new $class($record);
            $entities[$entity->id()] = $entity;
        }

        $this->model = $entities;

        return $this->model;
    }

    public function get()
    {
        return $this->model;
    }

    public function deleteAll()
    {
        $this->firebase->delete($this->endpoint);

        return $this;
    }

    public function query(array $query = [], $clean = false)
    {
        if ($clean) {
            $this->query = $query;
        } else {
            $this->query = array_merge($this->query, $query);
        }

        return $this;
    }

    public function top($limit)
    {
        $this->query['limitToFirst'] = (int) $limit;

        return $this;
    }

    public function tail($limit)
    {
        $this->query['limitToLast'] = (int) $limit;

        return $this;
    }

    public function orderBy($fieldName)
    {
        $this->query['orderBy'] = json_encode($fieldName);

        return $this;
    }
}
