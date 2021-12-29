<?php
namespace proc\scheduler\exception;
use proc\task;


class UnknownTaskTypeException extends LogicException
   {
      /** @var task\Task */
      protected $task=NULL;


      public function __construct(task\Task $unknownTask)
         {
            $this->task=$unknownTask;
            parent::__construct('Encountered task#'.$this->task->getID().' of unknown type: '.get_class($this->task));
         }
   }
