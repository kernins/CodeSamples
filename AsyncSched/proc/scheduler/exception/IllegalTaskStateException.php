<?php
namespace proc\scheduler\exception;
use proc\task;


class IllegalTaskStateException extends LogicException
   {
      /** @var task\Task */
      protected $task=NULL;


      public function __construct(task\Task $illegalTask)
         {
            $this->task=$illegalTask;
            parent::__construct('Task#'.$this->task->getID().' has illegal state: '.$this->task->getState());
         }
   }
