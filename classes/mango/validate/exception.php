<?php defined('SYSPATH') or die('No direct script access.');

class Mango_Validate_Exception extends Validate_Exception {

	/**
	 * @var  string  Name of model
	 */
	public $model;

	/**
	 * @var  int  Sequence number of model (if applicable)
	 */
	public $seq;

	public function __construct($model, Validate $array, $message = 'Failed to validate array', array $values = NULL, $code = 0)
	{
		$this->model = $model;

		parent::__construct($array, $message, $values, $code);
	}

} // End Mango_Validate_Exception
