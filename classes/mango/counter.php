<?php 

class Mango_Counter implements Mango_Interface {
	protected $_value = 0;
	protected $_changed = 0;
	protected $_new;
	
	public function __construct($value = NULL, $new = FALSE)
	{
		if(is_numeric($value))
		{
			$this->_value = (int) $value;
			$this->_new = $new;
		}
	}

	public function increment($value = 1)
	{
		$this->_value += $value;
		$this->_changed += $value;
	}

	public function decrement($value = 1)
	{
		$this->_value -= $value;
		$this->_changed -= $value;
	}

	public function as_array( $__get = FALSE )
	{
		return $this->_value;
	}

	public function get_changed($update, array $prefix = array())
	{
		if($update)
		{
			return ! empty($this->_changed) ? array('$inc' => array(implode('.',$prefix) => $this->_changed)) : array();
		}
		else
		{
			return ! empty($this->_changed) ? arr::path_set($prefix,$this->_value) : array();
		}
	}

	public function set_saved()
	{
		$this->_changed = 0;
	}

	public function __toString()
	{
		return (string) $this->_value;
	}
}