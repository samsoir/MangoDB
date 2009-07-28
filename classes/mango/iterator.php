<?php defined('SYSPATH') OR die('No direct access allowed.');

class Mango_Iterator implements Iterator, Countable {

	// Class attributes
	protected $_object_name;

	// MongoCursor object
	protected $_cursor;

	public function __construct($object_name, MongoCursor $cursor)
	{
		$this->_object_name = $object_name;
		$this->_cursor = $cursor;
	}

	public function cursor()
	{
		return $this->_cursor;
	}

	public function as_array()
	{
		$array = array();

		if($this->count())
		{
			$this->rewind();
			do
			{
				$current = $this->current();
				$array[ (string) $current->_id ] = $current;
			}
			while($this->next());
		}

		return $array;
	}

	/**
	 * Countable: count
	 */
	public function count()
	{
		return $this->_cursor->count();
	}

	/**
	 * Iterator: current
	 */
	public function current()
	{
		return Mango::factory($this->_object_name,$this->_cursor->current());
	}

	/**
	 * Iterator: key
	 */
	public function key()
	{
		return $this->_cursor->key();
	}

	/**
	 * Iterator: next
	 */
	public function next()
	{
		return $this->_cursor->next();
	}

	/**
	 * Iterator: rewind
	 */
	public function rewind()
	{
		$this->_cursor->rewind();
	}

	/**
	 * Iterator: valid
	 */
	public function valid()
	{
		return $this->_cursor->valid();
	}

} // End ORM Iterator