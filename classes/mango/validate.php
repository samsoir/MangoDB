<?php defined('SYSPATH') OR die('No direct access allowed.');

class Mango_Validate extends Kohana_Validate {

	public function offsetUnset($field)
	{
		unset($this->_labels[$field], $this->_filters[$field], $this->_rules[$field], $this->_callbacks[$field]);

		if ( isset($this[$field]))
		{
			parent::offsetUnset($field);
		}
	}

} // End Mango_Validate