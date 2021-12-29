<?php
namespace proc\worker;
use proc\ILogger, proc\messaging, proc\task;


final class WorkerAnalysis extends WorkerAbstract
   {
      const HANDLEABLE_FILE_STATES=[
         model\import\source\IStateEnumerator::STATE_NEW
      ];



      public function __construct(messaging\tasks\EndpointWorkerAnalysis $msgCtl, ILogger $logger=NULL)
         {
            parent::__construct($msgCtl, $logger);
         }



      protected function handle(model\import\source\EntryAbstract $srcEntry)
         {
            /** APP-SPECIFIC CODE REMOVED **/

            return new task\Task($file->getID(), $file->getAppID(), $file->getState(), $sharedResUsage);
         }
   }
