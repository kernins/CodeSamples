<?php
namespace proc\worker\exception;
use proc\task;


class TaskUnprocessableException extends RuntimeException
   {
      const R_MISSING         = 'missing';
      const R_LOCKED          = 'locked';
      const R_INVALID_STATE   = 'state';


      /** @var task\Task */
      protected $task=NULL;
      protected $reason=NULL;



      public function __construct(task\Task $unprocTask, $reason)
         {
            $this->task=$unprocTask;
            $this->reason=$reason;

            switch($reason)
               {
                  case self::R_MISSING:
                     $msg='underlying file is absent in storage';
                     break;
                  case self::R_LOCKED:
                     $msg='underlying file is locked or failed to lock';
                     break;
                  case self::R_INVALID_STATE:
                     $msg='underlying file has invalid/unexpected state (task arrived as '.$this->task->getState().')';
                     break;
                  default:
                     $msg='reason unknown';
               }
            parent::__construct('Task#'.$this->task->getID().' is unprocessable: '.$msg);
         }
   }
