<?php
namespace proc\worker;
use proc\ILogger, proc\messaging, proc\task;


abstract class WorkerAbstract
   {
      const HANDLEABLE_FILE_STATES=[];


      /** @var messaging\tasks\EndpointWorkerAbstract */
      private $_msgCtlIPC=NULL;

      /** @var ILogger */
      protected $logger=NULL;
      /** @var Safeguard */
      protected $safeGuard=NULL;


      protected $timeStarted=NULL;
      protected $tasksHandled=0;



      public function __construct(messaging\tasks\EndpointWorkerAbstract $msgCtl, ILogger $logger=NULL)
         {
            $this->timeStarted=time();
            if(!empty($logger)) $this->setLogger($logger);

            $this->_msgCtlIPC=$msgCtl;
            $this->_msgCtlIPC->initConsumer(function(task\Task $task) {
               try
                  {
                     if(!empty($this->logger)) $this->logger->info('Got new task#'.$task->getID().' [owner #'.$task->getOwnerID().'] in state '.$task->getState(), ILogger::SRC_HNDL);

                     /** APP-SPECIFIC CODE REMOVED **/

                     $resp=$this->handle($entry);
                     $this->tasksHandled++;

                     if(empty($resp)) throw new exception\LogicException('No processed Task definition was returned from '.get_class().'::handle()');
                     $this->_msgCtlIPC->publish($resp);

                     if(!empty($this->logger))
                        {
                           $this->logger->info('Done processing task#'.$resp->getID().' [owner #'.$resp->getOwnerID().'], new state: '.$resp->getState(), ILogger::SRC_HNDL);
                           $this->logger->memoryStat(ILogger::LVL_PERF, ILogger::SRC_HNDL);
                        }
                     return true; //acknowledge
                  }
               catch(IException $ex)
                  {
                     //One of worker-domain exceptions, e.g. exception\TaskUnprocessableException
                     //Subject file will be left as is (probably locked) to simplify subsequent investigation
                     //Normally such exceptions should never be thrown and should be treated as critical,
                     //potentially indicating worker crash (and task redelivery) or sched malfunction
                     if(!empty($this->logger)) $this->logger->err(['FATAL: ', $ex], ILogger::SRC_HNDL);
                     return false; //reject
                  }
               finally
                  {
                     if(!empty($this->logger)) $this->logger->resetCtxFile();

                     unset($task, $entry, $file, $resp);
                     gc_collect_cycles();
                     //FIXME: There is probably a circular reference on reader instance (at least iastkxml)
                     //GC will help, but better find and fix them
                  }
            });
         }

      final public function setSafeguard(Safeguard $sg)
         {
            $this->safeGuard=$sg;
            return $this;
         }

      public function setLogger(ILogger $logger)
         {
            $this->logger=$logger;
            $this->logger->setCtxWorker(getmypid(), array_slice(explode('\\', static::class), -1)[0]); //FIXME: don't really like getmypid() here
            return $this;
         }

      /** @return task\Task  Handled task */
      abstract protected function handle(model\import\source\EntryAbstract $task);



      final public function run()
         {
            while(true)
               {
                  if($this->_msgCtlIPC->waitForEventsAsync(0, 100000)) //had some events
                     {
                        $rt=time()-$this->timeStarted;
                        if(!empty($this->safeGuard) && $this->safeGuard->isLimitExceeded($rt, $this->tasksHandled))
                           {
                              if(!empty($this->logger))
                                 {
                                    $this->logger->warn('Terminating due to exceeding safeguard limit after '.$rt.' seconds and '.$this->tasksHandled.' tasks handled', ILogger::SRC_HNDL);
                                    $this->logger->memoryStat(ILogger::LVL_WARN, ILogger::SRC_HNDL);
                                 }
                              break;
                           }
                        elseif(!empty($this->logger)) $this->logger->perf('Running for '.$rt.' seconds, '.$this->tasksHandled.' tasks handled', ILogger::SRC_HNDL);
                     }
               }
            return $this;
         }
   }
