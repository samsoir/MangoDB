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

	public function as_array( $objects = TRUE )
	{
		$array = array();

		if($this->count())
		{
			$this->rewind();
			do
			{
				$current = $this->current();
				$array[ (string) $current->_id ] = $objects ? $current : $current->as_array();
			}
			while($this->next());
		}

		return $array;
	}

	// Return an (associative) array of values
	// $blog->comments->select_list('id','author');
	// $blog->comments->select_list('author');
	public function select_list($key = '_id',$val = NULL)
	{
		if($val === NULL)
		{
			$val = $key;
			$key = NULL;
		}

		$list = array();

		foreach($this->_cursor as $data)
		{
			if($key !== NULL)
			{
				$list[(string) $data[$key]] = $data[$val];
			}
			else
			{
				$list[] = $data[$val];
			}
		}

		return $list;
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