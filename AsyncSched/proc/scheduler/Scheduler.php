<?php
namespace proc\scheduler;
use proc\ILogger, proc\messaging, proc\task;


/**
 * Incapsulates relevant IPC logic and implements FairQueuing algo between different app's queues
 *
 * Scheduler expects all workers to be idle (and their queues empty) at the time it starts-up
 * as there is no clean/reasonable way to reliably synchronize to previously scheduled tasks queue
 */
final class Scheduler
   {
      const PROC_INTERVAL_USEC=50000;


      /** @var messaging\tasks\EndpointScheduler */
      private $_msgCtlIPC=NULL;

      /** @var ILogger */
      private $_logger=NULL;


      /** @var Queue[] Scheduling queues by ownerID [oid=>queue] */
      private $_schedQueues=[];


      //published: actually running + backlog
      //cumulative across all owners/apps
      private $_schedTargetCntAnalysis=16;
      private $_schedTargetCntImport=16;



      public function __construct(messaging\tasks\EndpointScheduler $msgCtl, ILogger $logger=NULL)
         {
            if(!empty($logger))
               {
                  $this->_logger=$logger;
                  $this->_logger->setCtxSched(getmypid()); //FIXME: don't really like getmypid() here
               }

            $this->_msgCtlIPC=$msgCtl;
            //TODO: refactor closures to methods? implement iface and pass $this?
            $this->_msgCtlIPC->initConsumer(
               function(task\Task $taskIngress) {
                  if(!empty($this->_logger))
                     {
                        $this->_logger->info(
                           'Got new task #'.$taskIngress->getID().' [owner #'.$taskIngress->getOwnerID().'] in state '.$taskIngress->getState(),
                           ILogger::SRC_HNDL
                        );
                     }
                  $this->addTask($taskIngress);
               },
               function(task\Task $taskProcessed) {
                  if(!empty($this->_logger))
                     {
                        $this->_logger->info(
                           'Got update for task #'.$taskProcessed->getID().' [owner #'.$taskProcessed->getOwnerID().'] in state '.$taskProcessed->getState(),
                           ILogger::SRC_HNDL
                        );
                     }
                  $queue=$this->_getQueueForApp($taskProcessed->getOwnerID());
                  //workers must be idle (and their queues empty) on sched start-up, so any legitimate 'processed' tasks ARE already in sched-queue
                  //completed tasks will be automatically dequeued (and scheduler should not care about such details, its queue's responsibility)
                  $queue->update($taskProcessed);
               },
               function(task\Task $taskRefused, $isRejected /*TRUE=rejected(unprocessable), FALSE=returned(unroutable)*/) {
                  if(!empty($this->_logger))
                     {
                        $this->_logger->warn(
                           'Got '.($isRejected? 'REJECTED':'RETURNED').'] task #'.$taskRefused->getID().' [owner #'.$taskRefused->getOwnerID().'] in state '.$taskRefused->getState(),
                           ILogger::SRC_HNDL
                        );
                     }
                  //$taskRefused here is original task as published previously by self::_publishTasks()
                  $queue=$this->_getQueueForApp($taskRefused->getOwnerID());
                  if($isRejected) $queue->remove($taskRefused->getID()); //unprocessable
                  else $queue->revertScheduled($taskRefused->getID());   //returned unroutable, reverting
                  //pause/lower self::_publishTasks() frequency in case of returns
               }
            );
         }



      public function addTask(task\Task $task)
         {
            //Queue::append() will throw an exception on invalid state or duplicate task
            //Duplicate task enqueue attempts are valid at the sched startup time, when same tasks could be sourced from both AMQ & DB
            //FIXME: refactor sched startup & tasks recovery, so there will be no legit dup-cases
            $q=$this->_getQueueForApp($task->getOwnerID());
            if(!$q->has($task->getID())) $q->append($task);
            elseif(!empty($this->_logger))
               {
                  $this->_logger->warn(
                     'Attempted to enqueue duplicate task #'.$task->getID().' [owner #'.$task->getOwnerID().'] in state '.$task->getState(),
                     ILogger::SRC_HNDL
                  );
               }
            return $this;
         }



      public function runCycle($waitSec=0, $waitUsec=self::PROC_INTERVAL_USEC)
         {
            $hasEvents=false;
            for($iter=0; $iter<100; $iter++) //to prevent infinite loop on constanly-high-rate msg flow (unlikely, but still theoretically possible)
               {
                  //waitForEventsAsync() will wait at most sec.usec for events to process and will process at most one message prior returning
                  //so lets try to handle all pending messages in queue before running next schedCycle, this should improve performance in case tasks are produced/published in batches
                  if($this->_msgCtlIPC->waitForEventsAsync($waitSec, $waitUsec)) $hasEvents=true;
                  else break; //as soon as there are no more events
               }

            if($hasEvents)
               {
                  //pick and publish new tasks to workers
                  $this->_publishTasks();
               }
            return $hasEvents;
         }

      public function run()
         {
            //handling recovered/pre-filled queue
            $this->_publishTasks();

            $i=0;
            $iTrg=(int)(60e6/self::PROC_INTERVAL_USEC);

            while(true)
               {
                  $this->runCycle(0, self::PROC_INTERVAL_USEC);

                  if(!empty($this->_logger) && $this->_logger->isLoggable(ILogger::LVL_PERF, ILogger::SRC_HNDL) && (++$i >= $iTrg))
                     {
                        $i=0;
                        $totalSchedAnl=$totalSchedImp=$totalQLen=$cntActive=0;
                        foreach($this->_schedQueues as $oid=>$queue)
                           {
                              if(($cnt=count($queue)) > 0)
                                 {
                                    $totalQLen+=$cnt;
                                    $totalSchedAnl+=$queue->countScheduledAnalysis();
                                    $totalSchedImp+=$queue->countScheduledImport();
                                    $cntActive++;

                                    $this->_logger->perf(
                                       'Queue#'.$oid.': [len: '.$cnt.', schedAnl: '.$queue->countScheduledAnalysis().', schedImp: '.$queue->countScheduledImport().']',
                                       ILogger::SRC_HNDL
                                    );
                                 }
                           }
                        if($cntActive > 0)
                           {
                              $this->_logger->perf(
                                 '['.date('d.m.Y H:i:s').'] Active queues: '.$cntActive.', tasks: '.$totalQLen.', schedAnl: '.$totalSchedAnl.', schedImp: '.$totalSchedImp,
                                 ILogger::SRC_HNDL
                              );
                              $this->_logger->memoryStat(ILogger::LVL_PERF, ILogger::SRC_HNDL);
                           }
                        else $this->_logger->perf('['.date('d.m.Y H:i:s').'] No active queues, idling...', ILogger::SRC_HNDL);
                     }
               }
            return $this;
         }



      private function _publishTasks()
         {
            $nonEmptyQueues=[]; $schedCntAnalysis=$schedCntImport=0;
            //TODO: track these persistently (make props)?
            //    non-empty: on enqueue/dequeue
            //    schedCnt: on task publish/wrk-back-report/dequeue
            foreach($this->_schedQueues as $oid=>$queue)
               {
                  if(!$queue->isEmpty()) //also filtering-out empty ones on ocassion
                     {
                        $queue->resetCursor(); //resetting queue's cursors
                        $nonEmptyQueues[$oid]=$queue;
                        $schedCntAnalysis+=$queue->countScheduledAnalysis();
                        $schedCntImport+=$queue->countScheduledImport();
                     }
               }
            if(!empty($this->_logger))
               {
                  $this->_logger->info(
                     'Going to publish new tasks for workers [sched-ANL '.$schedCntAnalysis.'/'.$this->_schedTargetCntAnalysis.', sched-IMP '.$schedCntImport.'/'.$this->_schedTargetCntImport.', '.
                     count($nonEmptyQueues).' queues non-empty]...',
                     ILogger::SRC_HNDL
                  );
                  if(!empty($nonEmptyQueues)) $this->_logger->dtl('Non-empty queues: '.implode(', ', array_keys($nonEmptyQueues)), ILogger::SRC_HNDL);
               }

            //processing import first, as 'ready' tasks should all reside in queue head
            $validQueues=$nonEmptyQueues; $iter=0;
            while(($schedCntImport<$this->_schedTargetCntImport) && !empty($validQueues))
               {
                  //FairQueuing algo, trying to pick tasks from all queues equally, taking into account already running cnt
                  //first trying queues with 0 running tasks, gradually increasing the limit with each iteration until global targetCnt is reached
                  foreach($validQueues as $oid=>$queue)
                     {
                        if(($cnt=$queue->countScheduledImport()) <= $iter)
                           {
                              if(!empty($task=$queue->getNextForImport()))
                                 {
                                    if(!empty($this->_logger))
                                       {
                                          $this->_logger->dbg(
                                             '...publishing IMP task #'.$task->getID().' [owner #'.$task->getOwnerID().'] in state '.$task->getState(),
                                             ILogger::SRC_HNDL
                                          );
                                       }
                                    $this->_msgCtlIPC->publish($task, messaging\tasks\EndpointScheduler::OT_IMPORT);
                                    $queue->setScheduled($task->getID()); //marking task as scheduled
                                    $schedCntImport++;
                                 }
                              else unset($validQueues[$oid]); //end-of-queue or some hard limit reached, excluding to prevent infinite loop
                           }
                        //otherwise skipping for current iter
                        elseif(!empty($this->_logger)) $this->_logger->dbg('...skipping IMP queue#'.$oid.' due to sched('.$cnt.') > iter('.$iter.')', ILogger::SRC_HNDL);
                     }
                  $iter++;
               }
            if(!empty($this->_logger))
               {
                  $this->_logger->info(
                     'Done processing IMP queues: '.$schedCntImport.'/'.$this->_schedTargetCntImport.' tasks currently scheduled, '.count($validQueues).' valid queues remain',
                     ILogger::SRC_HNDL
                  );
                  if(!empty($validQueues)) $this->_logger->dtl('Valid queues remain: '.implode(', ', array_keys($validQueues)), ILogger::SRC_HNDL);
               }

            //now cursor must be at-or-near 'new'/'analysing' tasks area
            $validQueues=$nonEmptyQueues; $iter=0;
            while(($schedCntAnalysis<$this->_schedTargetCntAnalysis) && !empty($validQueues))
               {
                  foreach($validQueues as $oid=>$queue)
                     {
                        if(($cnt=$queue->countScheduledAnalysis()) <= $iter)
                           {
                              if(!empty($task=$queue->getNextForAnalysis()))
                                 {
                                    if(!empty($this->_logger))
                                       {
                                          $this->_logger->dbg(
                                             '...publishing ANL task #'.$task->getID().' [owner #'.$task->getOwnerID().'] in state '.$task->getState(),
                                             ILogger::SRC_HNDL
                                          );
                                       }
                                    $this->_msgCtlIPC->publish($task, messaging\tasks\EndpointScheduler::OT_ANALYSIS);
                                    $queue->setScheduled($task->getID());
                                    $schedCntAnalysis++;
                                 }
                              else unset($validQueues[$oid]);
                           }
                        elseif(!empty($this->_logger)) $this->_logger->dbg('...skipping ANL queue#'.$oid.' due to sched('.$cnt.') > iter('.$iter.')', ILogger::SRC_HNDL);
                     }
                  $iter++;
               }
            if(!empty($this->_logger))
               {
                  $this->_logger->info(
                     'Done processing ANL queues: '.$schedCntAnalysis.'/'.$this->_schedTargetCntAnalysis.' tasks currently scheduled, '.count($validQueues).' valid queues remain',
                     ILogger::SRC_HNDL
                  );
                  if(!empty($validQueues)) $this->_logger->dtl('Valid queues remain: '.implode(', ', array_keys($validQueues)), ILogger::SRC_HNDL);
               }
         }


      /** @return Queue */
      private function _getQueueForApp($ownerID)
         {
            //TODO: make traversalMaxDepth dependent on schedTargetCnt?
            if(!isset($this->_schedQueues[$ownerID])) $this->_schedQueues[$ownerID]=new Queue($ownerID, $traversalDepthAnalysis=NULL, $traversalDepthImport=NULL);
            return $this->_schedQueues[$ownerID];
         }
   }
