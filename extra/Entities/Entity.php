<?php
/**
 * PHP-Firebase
 *
 * @link      https://github.com/adrorocker/php-firebase
 * @copyright Copyright (c) 2018 Adro Rocker
 * @author    Adro Rocker <mes@adro.rocks>
 */
namespace PhpFirebase\Entities;

use ReflectionObject;
use ReflectionProperty;

class Entity implements EntityInterface
{
    use Call;

    public function __construct(array $properties)
    {
        $this->fill($properties);
    }

    public function toArray()
    {
        $bridge = new Bridge($this);
        $array = [];
        foreach ($bridge->getProperties() as $key => $value) {
            $array[$key] = $this->$key;
        }

        return json_decode(json_encode($array), true);
    }

    public function toJson()
    {
        return json_encode($this->toArray());
    }

    public static function fromJson($json)
    {
        $array = json_decode($json, true);
        $class = get_called_class();

        return new $class($array);
    }

    protected function fill(array $properties)
    {
        foreach ($properties as $key => $value) {
            if (true == property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}
