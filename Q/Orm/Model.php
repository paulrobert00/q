<?php

namespace Q\Orm;

use Q\Orm\Migration\TableModelFinder;

/**
 * A model that can represent both abstract ideas and humans alike.
 */
abstract class Model
{

    /**
     * Construct a new model. This does not persist the object.
     * 
     * @param array $fields
     */
    public function __construct($fields = [])
    {
        $properties = Helpers::getModelProperties(static::class);
        foreach ($fields as $k => $v) {
            if (in_array($k, $properties)) {
                $this->$k = $v;
            }
        }
    }

    public function __call($name, $args)
    {
        if (isset($this->$name)) {
            if ($this->$name instanceof \Closure) {
                $this->$name = ($this->$name)();
                //Reset prevState, since we just opened up a closure
                $prevState = $this->prevState();
                foreach ($prevState as $k => $v) {
                    if ($k === $name) {
                        $prevState[$k] = $this->$name;
                    }
                }
                $this->prevState($prevState);
            }
            return $this->$name;
        }
        return null;
    }

    public function __get($name)
    {
        return null;
    }

    public function __set($name, $value)
    {
        //This is for columns from a join
        $this->$name = $value;
    }

    public function __toString()
    {
        $pk = $this->pk();
        $v = $this->$pk;
        return static::class . "($v)";
    }

    /**
     * Get the primary key field of this model.
     * 
     * @return string
     */
    public function pk(): string
    {
        return TableModelFinder::findModelPk(static::class);
    }

    /**
     * Saves the object to the database.
     * 
     * @return Q\Orm\Model|null
     */
    public function save()
    {
        $pk = $this->pk();
        $object_props = get_object_vars($this);
        if (array_key_exists($pk, $object_props)) {

            return static::items()->filter([$pk => $this->$pk])->update($object_props, $this->prevState())->one();
        } else {
            if ($pk === 'id') {
                return static::items()->create($object_props)->order_by('id DESC')->one();
            } else {
                return static::items()->create($object_props)->order_by("$pk DESC")->one();
            }
        }
    }

    /**
     * Used to get and state the previous state of a model.
     * 
     * @param array|null $assoc
     * 
     * @return array
     */
    public function prevState(array $assoc = null): array
    {
        static $state;
        if ($assoc) {
            $state = $assoc;
        } else {
            if ($state) {
                return $state;
            }
        }
        return [];
    }

    /**
     * Reload the model from the database.
     * 
     * @return Model
     */
    public function reload(): Model
    {
        $prevState = $this->prevState();
        $isDirty = false;
        if ($prevState) {
            foreach ($prevState as $k => $v) {
                if ($this->$k !== $v) {
                    $isDirty = true;
                }
            }
        }

        if ($isDirty) {
            $pk = $this->pk();
            $obj = static::items()->filter([$pk => $this->$pk])->one(true);
            foreach (get_object_vars($obj) as $k => $v) {
                $this->$k = $v;
            }

            $this->prevState(get_object_vars($obj));
        }
        return $this;
    }

    /**
     * Get a JSON representation of a model.
     * 
     * @param bool $expandLists
     * 
     * @return string|false
     */
    public function json($expandLists = false)
    {
        $vars = get_object_vars($this);
        foreach ($vars as $k => $v) {
            if ($v instanceof \Closure) {
                $vars[$k] = call_user_func($v);
            }
        }
        if ($expandLists === true) {
            foreach ($vars as $k => $v) {
                if ($v instanceof Handler) {
                    $vars[$k] = iterator_to_array($v->all());
                }
            }
        }
        return json_encode($vars, JSON_PRETTY_PRINT);
    }

    /**
     * All the objects that belong to a model.
     * 
     * @return Handler
     */
    public static function items(): Handler
    {
        return new Handler(static::class);
    }

    /**
     * Get the user defined schema.
     * 
     * @return array
     */
    public static abstract function schema(): array;
}
