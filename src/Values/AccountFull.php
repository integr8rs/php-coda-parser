<?php

namespace Codelicious\Coda\Values;


use function Codelicious\Coda\Helpers\validateStringLength;

class AccountFull
{
	/** @var string */
	private $value;
	
	public function __construct(string $value)
	{
		validateStringLength($value, 37, "AccountFull");
		
		$this->value = trim($value);
	}
	
	public function getValue(): string
	{
		return $this->value;
	}
}