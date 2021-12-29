<?php
namespace proc\scheduler\exception;
use proc\scheduler\IException;


class DomainException extends \DomainException implements IException {}
