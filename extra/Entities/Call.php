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

use BadMethodCallException;
use ReflectionClass;
use ReflectionProperty;

trait Call
{
    /**
     * Get or Set property.
     *
     * @param string $name      Name of the method
     * @param array  $arguments Arguments
     *
     * @throws BadMethodCallException If the $name is not a property
     */
    public function __call($name, $arguments)
    {
        if (true !== property_exists($this, $name)) {
            throw new BadMethodCallException(sprintf(
                'The metod "%s" does not exist',
                $name
            ));
        }
        if ($arguments && count($arguments) == 1) {
            $reflect = new ReflectionClass($this);
            $props = $reflect->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED);
            foreach ($props as $prop) {
                if ($prop->getName() == $name) {
                    $this->{$name} = $arguments[0];
                }
            }
        }

        return $this->{$name};
    }
}
