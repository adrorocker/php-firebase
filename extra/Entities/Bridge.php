<?php
/**
 * PHP-Firebase.
 *
 * @link      https://github.com/adrorocker/php-firebase
 *
 * @copyright Copyright (c) 2018 Adro Rocker
 * @author    Adro Rocker <mes@adro.rocks>
 */

namespace PhpFirebase\Entities;

use ReflectionObject;
use ReflectionProperty;

class Bridge
{
    /**
     * @var object
     */
    protected $object;

    /**
     * @var array
     */
    protected $properties = [];

    /**
     * Make non-public members of the given object accessible.
     *
     * @param object $object.- Object which members we'll make accessible
     */
    public function __construct($object)
    {
        $this->object = $object;
        $reflected = new ReflectionObject($this->object);
        $this->properties = [];

        $properties = $reflected->getProperties(
            ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PRIVATE | ReflectionProperty::IS_PUBLIC
        );

        foreach ($properties as $property) {
            $property->setAccessible(true);
            $this->properties[$property->getName()] = $property;
        }
    }

    public function getProperties()
    {
        return $this->properties;
    }

    /**
     * Returns a property of $this->object.
     *
     * @param string $name
     *
     * @return mixed
     */
    public function __get($name)
    {
        // If the property is exposed (with reflection) then we use getValue()
        // to access it, else we access it directly
        if (isset($this->properties[$name])) {
            return $this->properties[$name]->getValue($this->object);
        }
    }
}
