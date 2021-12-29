<?php
namespace proc\worker;


class Safeguard
   {
      protected $limitMemory=NULL;
      protected $limitRuntime=NULL;
      protected $limitTasks=NULL;



      public function __construct($limitMemoryMiB, $limitRuntimeSec=NULL, $limitTasks=NULL)
         {
            if(!is_numeric($limitMemoryMiB) || (($limitMemoryMiB=(int)$limitMemoryMiB)<=0)) throw new exception\UnexpectedValueException('None or invalid memory limit given');
            $this->limitMemory=$limitMemoryMiB*1024*1024;

            if($limitRuntimeSec!==NULL)
               {
                  if(!is_numeric($limitRuntimeSec) || (($limitRuntimeSec=(int)$limitRuntimeSec)<=0)) throw new exception\UnexpectedValueException('Invalid runtime limit given');
                  $this->limitRuntime=$limitRuntimeSec;
               }
            if($limitTasks!==NULL)
               {
                  if(!is_numeric($limitTasks) || (($limitTasks=(int)$limitTasks)<=0)) throw new exception\UnexpectedValueException('Invalid tasks limit given');
                  $this->limitTasks=$limitTasks;
               }
         }


      public function isLimitExceeded($runtimeSec, $tasksHandled)
         {
            return
               (memory_get_peak_usage(true) >= $this->limitMemory) ||
               !empty($this->limitRuntime) && ($runtimeSec >= $this->limitRuntime) ||
               !empty($this->limitTasks) && ($tasksHandled >= $this->limitTasks);
         }
   }
