<?php
namespace proc\scheduler\exception;
use proc\task;


class SharedResInfoMissingException extends LogicException
   {
      /** @var task\Task */
      protected $task=NULL;


      public function __construct(task\Task $task)
         {
            $this->task=$task;
            parent::__construct('Task#'.$this->task->getID().' in state ['.$this->task->getState().'] has no SharedResUsage info attached');
         }
   }
