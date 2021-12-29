<?php
namespace proc\worker\exception;
use proc\worker\IException;


class UnexpectedValueException extends \UnexpectedValueException implements IException {}
