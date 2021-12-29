<?php
namespace proc\scheduler\exception;
use proc\scheduler\IException;


class OutOfRangeException extends \OutOfRangeException implements IException {}
