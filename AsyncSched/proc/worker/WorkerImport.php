<?php
namespace proc\worker;
use proc\ILogger, proc\messaging, proc\task;


final class WorkerImport extends WorkerAbstract
   {
      const HANDLEABLE_FILE_STATES=[
         model\import\source\IStateEnumerator::STATE_READY
      ];


      const SPH_BULK_BUFF_SIZE = 100;



      public function __construct(messaging\tasks\EndpointWorkerImport $msgCtl, ILogger $logger=NULL)
         {
            parent::__construct($msgCtl, $logger);
         }



      protected function handle(model\import\source\EntryAbstract $srcEntry)
         {
            /** APP-SPECIFIC CODE REMOVED **/

            return new task\Task($file->getID(), $file->getAppID(), $file->getState());
         }
   }
