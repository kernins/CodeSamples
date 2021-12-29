<?php
namespace proc\scheduler;
use proc\task, utils\UtilNumeric;


/**
 * Scheduler queue, each instance represents queue for one specific app (appID)
 * Incapsulates single-app-scope scheduling logic
 *
 * Main purposes:
 *    - keep entries in correct order
 *    - keep track of scheduled (published to workers exchange) entries
 *    - pick next suitable entry for scheduling, ensuring both conflictlessness (in terms of sharedResUsage) and tasks order/timeline preservation (see Queue_Cursor)
 *
 * Entries in queue are virtually divided into two main groups: those in new/shdAnalysis states and those in ready/shdImport (also see Entry class docs)
 * In simplest case state graph will look like: new->shdAnalysis (sharedResUsage info becomes available)->ready->shdImport->completed (and dequeued).
 * Each entry preserves its position in queue (in relation to other entries) during its whole lifecycle, i.e. until dequeued when 'completed'
 *
 * Due to differences between these groups (see Entry class docs), separate getNext*() methods implemented, allowing for independent scheduling and different limits for each group.
 * It is generally desirable to have 'new' entries analyzed ASAP to have big enough backlog of 'ready' ones for scheduler to be able
 * to efficiently utilize all available import-workers while staying within defined constraints
 */
final class Queue implements \Countable
   {
      /** @var int Target/owning app ID */
      private $_ownerID=NULL;
      /** @var Entry[] [id => entry] */
      private $_queue=[]; //TODO: replace with ds/Vector (http://php.net/manual/en/class.ds-vector.php)?


      /** @var int Max depth the cursor will go towards queue tail each getNextForAnalysis() call */
      private $_cursorTraversalMaxDepthAnalysis=50;
      /** @var int Max depth the cursor will go towards queue tail each getNextForImport() call */
      private $_cursorTraversalMaxDepthImport=20;

      /** @var Queue_CursorAnalysis */
      private $_cursorAnalysis=NULL;
      /** @var Queue_CursorImport */
      private $_cursorImport=NULL;

      /**
       * Scheduled tasks counters (tasks, published to workers exchange for processing)
       * Used in scheduler to count scheduled tasks (per-owner and globally) and in FairQueuing algorithm as 'UsedShare' metric
       */
      private $_cntScheduledAnalysis=0;
      private $_cntScheduledImport=0;



      public function __construct($ownerID, $traversalMaxDepthAnalysis=NULL, $traversalMaxDepthImport=NULL)
         {
            if(!$ownerID=UtilNumeric::absInt($ownerID)) throw new exception\InvalidArgumentException('None or invalid ownerID given');
            $this->_ownerID=$ownerID;

            if($traversalMaxDepthAnalysis!==NULL)
               {
                  if($traversalMaxDepthAnalysis>0) $this->_cursorTraversalMaxDepthAnalysis=(int)$traversalMaxDepthAnalysis;
                  else throw new exception\InvalidArgumentException('Cursor traversal max depth must be positive integer, '.$traversalMaxDepthAnalysis.' given');
               }
            if($traversalMaxDepthImport!==NULL)
               {
                  if($traversalMaxDepthImport>0) $this->_cursorTraversalMaxDepthImport=(int)$traversalMaxDepthImport;
                  else throw new exception\InvalidArgumentException('Cursor traversal max depth must be positive integer, '.$traversalMaxDepthImport.' given');
               }
         }



      /**
       * Append new task to the tail of the queue
       * @param task\Task $task
       * @return Queue
       */
      public function append(task\Task $task)
         {
            if($task->getOwnerID()!=$this->_ownerID) throw new exception\DomainException('Attempted to enqueue a task with different owner ('.$this->_ownerID.' <- '.$task->getOwnerID().')');
            if($this->has($task->getID())) throw new exception\LogicException('Attempted to enqueue duplicate task#'.$task->getID()); //TODO: replace with DuplicateEntryException?

            $entry=new Entry($task); //will throw an exception if given an 'analyzing'/'importing' task
            if($entry->isCompleted()) throw new exception\LogicException('Attempted to enqueue completed task #'.$task->getID()); //TODO: replace with IllegalEntryStateException?

            $this->_queue[$entry->getID()]=$entry;
            return $this;
         }

      /**
       * Update a task already in queue. Completed tasks will be REMOVED
       * @param task\Task $task
       * @return Queue
       */
      public function update(task\Task $task)
         {
            if(empty($entry=$this->_getEntry($task->getID()))) throw new exception\OutOfRangeException('There is no task #'.$task->getID().' in this queue');

            $this->_onEntryStateLeave($entry->getState());
            $entry->syncToUpdatedTask($task);

            if($entry->isCompleted()) $this->_remove($entry->getID());
            else $this->_onEntryStateEnter($entry->getState());

            return $this;
         }

      /**
       * @param int $id
       * @return Queue
       */
      public function remove($id)
         {
            //meant only for completed?
            if(!empty($entry=$this->_getEntry($id))) //TODO: be strict and throw an exception, like in other methods?
               {
                  $this->_onEntryStateLeave($entry->getState());
                  $this->_remove($id);
               }
            return $this;
         }

      private function _remove($id)
         {
            unset($this->_queue[$id]);
         }


      /**
       * Mark task with given ID as scheduled (e.g. published to workers exchange)
       * @param int $id
       * @return Queue
       */
      public function setScheduled($id)
         {
            if(!$this->_changeEntryState($id, [Entry::STATE_NEW=>Entry::STATE_SHD_ANALYSIS, Entry::STATE_READY=>Entry::STATE_SHD_IMPORT]))
               throw new exception\LogicException('setScheduled() called for task#'.$id.' in '.$this->_getEntry($id)->getState().' state');

            return $this;
         }

      /**
       * Revert previously scheduled task to its original state (e.g. in case it can not be delivered to worker atm) and consider it not scheduled
       * @param int $id
       * @return Queue
       */
      public function revertScheduled($id)
         {
            if(!$this->_changeEntryState($id, [Entry::STATE_SHD_ANALYSIS=>Entry::STATE_NEW, Entry::STATE_SHD_IMPORT=>Entry::STATE_READY]))
               throw new exception\LogicException('revertScheduled() called for task#'.$id.' in '.$this->_getEntry($id)->getState().' state');

            return $this;
         }

      private function _changeEntryState($id, array $graph)
         {
            if(empty($entry=$this->_getEntry($id))) throw new exception\OutOfRangeException('There is no task #'.$id.' in this queue');

            $prevState=$entry->getState();
            if(!empty($stChanged=$entry->gotoState($graph)))
               {
                  $this->_onEntryStateLeave($prevState);
                  $this->_onEntryStateEnter($entry->getState());
               }
            return $stChanged;
         }

      /** @return Entry */
      private function _getEntry($id)
         {
            return $this->has($id)? $this->_queue[$id] : NULL;
         }



      public function has($id)
         {
            return isset($this->_queue[$id]);
         }


      public function isEmpty()
         {
            return empty($this->_queue);
         }



      /**
       * Returns next task suitable for analysis, ensuring correct order and conflictlessness with all previously returned tasks since last resetCursor() call
       * @see Queue_CursorAnalysis
       *
       * @return task\Task  Returns Task instance or NULL if end-of-queue or some limit reached
       */
      public function getNextForAnalysis()
         {
            if(empty($this->_cursorAnalysis)) $this->_cursorAnalysis=new Queue_CursorAnalysis($this->_cursorTraversalMaxDepthAnalysis);
            return empty($e=$this->_cursorAnalysis->findNext($this->_queue))? NULL : $e->getTask();
         }

      /**
       * Returns next task suitable for import, ensuring correct order and conflictlessness with all previously returned tasks since last resetCursor() call
       * @see Queue_CursorImport
       *
       * @return task\Task  Returns Task instance or NULL if end-of-queue or some limit reached
       */
      public function getNextForImport()
         {
            if(empty($this->_cursorImport)) $this->_cursorImport=new Queue_CursorImport($this->_cursorTraversalMaxDepthImport);
            return empty($e=$this->_cursorImport->findNext($this->_queue))? NULL : $e->getTask();
         }

      /**
       * Meant to be called at the beginning of each FairQueuing cycle
       * Will reset queue cursors and pointer
       * @return Queue
       */
      public function resetCursor()
         {
            reset($this->_queue);
            $this->_cursorAnalysis=$this->_cursorImport=NULL;
            return $this;
         }



      public function count()
         {
            return count($this->_queue);
         }

      public function countScheduledAnalysis()
         {
            return $this->_cntScheduledAnalysis;
         }

      public function countScheduledImport()
         {
            return $this->_cntScheduledImport;
         }



      private function _onEntryStateEnter($state)
         {
            switch($state)
               {
                  case Entry::STATE_SHD_ANALYSIS:
                     $this->_cntScheduledAnalysis++;
                     break;
                  case Entry::STATE_SHD_IMPORT:
                     $this->_cntScheduledImport++;
                     break;
               }
         }

      private function _onEntryStateLeave($state)
         {
            switch($state)
               {
                  case Entry::STATE_SHD_ANALYSIS:
                     $this->_cntScheduledAnalysis--;
                     if($this->_cntScheduledAnalysis<0) throw new exception\LogicException('SchedQueue cntRunningAnalysis underflow'); //should never happen, so LogicException
                     break;
                  case Entry::STATE_SHD_IMPORT:
                     $this->_cntScheduledImport--;
                     if($this->_cntScheduledImport<0) throw new exception\LogicException('SchedQueue cntRunningImport underflow');
                     break;
               }
         }
   }



/**
 * Meant to be private to Queue, but there is no such thing in PHP
 */
abstract class Queue_Cursor
   {
      const ST_BREAK    = -1;
      const ST_CONTINUE = 0;
      const ST_FOUND    = 1;


      /** @var int   Max traversal depth, will only dig this deep into the queue (counting from queue ptr pos at the time of first findNext() call) */
      private $_maxDepth=NULL;

      /** @var int   Traversed entries counter, maxDepth checks against this */
      private $_cntTraversed=0;



      public function __construct($maxDepth=NULL)
         {
            //primary validation must be done in Queue class
            if($maxDepth>0) $this->_maxDepth=(int)$maxDepth;
         }



      /**
       * @param Entry[] $inQueue    Queue to work on
       * @return Entry  Retruns next suitable entry or NULL
       */
      public function findNext(array $inQueue)
         {
            $found=false;
            //Starting with current entry, i.e. the entry the cursor was on at the time previous call returned
            //This way tasks picked and returned by prev call may be accounted for in current call ctx
            if(!empty($entry=current($inQueue)))
               {
                  /* @var $entry Entry */
                  do
                     {
                        if(!empty($this->_maxDepth) && ($this->_cntTraversed>=$this->_maxDepth)) break;
                        switch($this->evaluateEntry($entry))
                           {
                              //pointer will be kept at current task
                              case self::ST_BREAK:
                                 break 2; //break loop
                              case self::ST_FOUND:
                                 $found=true;
                                 break 2; //break loop
                              //otherwise continue to the next entry
                           }
                        $this->_cntTraversed++;
                     }
                  while(!empty($entry=next($inQueue)));
               }
            return $found? $entry : NULL;
         }

      /** @return int   self::ST_BREAK / self::ST_CONTINUE / self::ST_FOUND */
      abstract protected function evaluateEntry(Entry $entry);
   }

final class Queue_CursorAnalysis extends Queue_Cursor
   {
      protected function evaluateEntry(Entry $entry)
         {
            //analysis tasks are non-conflicting, there is no SharedRes to be tracked
            return $entry->isInState(Entry::STATE_NEW)? self::ST_FOUND : self::ST_CONTINUE;
         }
   }

final class Queue_CursorImport extends Queue_Cursor
   {
      /**
       * Cumulative shared resource usage tracker, all tasks traversed so far by this cursor instance are accounted
       * @var array [resIdent=>isExclusive]
       */
      private $_sharedResUsageTracker=[];



      protected function evaluateEntry(Entry $entry)
         {
            $ret=self::ST_CONTINUE; //continue by default
            if($entry->isInState([Entry::STATE_NEW, Entry::STATE_SHD_ANALYSIS]))
               {
                  //Iterating until first 'new' or 'analyzing' entry, anything after that should generally also be 'new'/'analyzing',
                  //except cases when entries are enqueued in different state in the first place (e.g. in 'ready' state)
                  //In any case, first 'new' or 'analyzing' entry should block any further entries from being processed/scheduled due to lack of sharedResUsage info for that entry
                  $ret=self::ST_BREAK; //stop traversing
               }
            elseif($entry->isInState(Entry::STATE_SHD_IMPORT))
               {
                  $this->_collectSharedResUsageInfo($entry);
                  //and continue
               }
            elseif($entry->isInState(Entry::STATE_READY))
               {
                  if($this->_isAnySharedResConflicts($entry)) //conflicting task, skipping
                     {
                        //collecting sharedResUsage even for skipped tasks - no gaps are allowed in order to ensure both conflictlessness and correct order at the same time
                        //example for assort&pharms: TaskA[phm 1,2,3] importing, TaskB[1,2,7] blocked by [1,2] and skipped, TaskC[5,6,7] must also be blocked due to [7] being affected by TaskB
                        $this->_collectSharedResUsageInfo($entry);
                        //and continue
                     }
                  else $ret=self::ST_FOUND; //no conflicts, picking entry
               }
            return $ret;
         }


      private function _collectSharedResUsageInfo(Entry $entry)
         {
            //NULL indicates task is missing SRU declaration instance, should never happen and be treated as critical bug otherwise
            if(($sru=$entry->getSharedResUsageDeclaration())===NULL) throw new exception\SharedResInfoMissingException($entry->getTask()); //TODO: maybe simplify to plain string message?

            foreach($sru as $resIdent=>$isExclusive)
               {
                  //as sharedResUsage tracker is cumulative and gapless (also include info for skipped conflicting entries),
                  //collisions are allowed here, and merging must end up with most restrictive mode amongst all traversed tasks
                  //i.e. if there is at least one task requiring exclusive access, the resulting def for this resIdent must also be exclusive
                  if(isset($this->_sharedResUsageTracker[$resIdent])) $isExclusive|=$this->_sharedResUsageTracker[$resIdent];
                  $this->_sharedResUsageTracker[$resIdent]=(bool)$isExclusive;
               }
         }

      private function _isAnySharedResConflicts(Entry $entry)
         {
            //NULL indicates task is missing SRU declaration instance, should never happen and be treated as critical bug otherwise
            if(($sru=$entry->getSharedResUsageDeclaration())===NULL) throw new exception\SharedResInfoMissingException($entry->getTask()); //TODO: maybe simplify to plain string message?

            foreach($sru as $resIdent=>$isExclusive)
               {
                  //conflicts if either wants exclusive access
                  if(isset($this->_sharedResUsageTracker[$resIdent]) && ($this->_sharedResUsageTracker[$resIdent]||$isExclusive)) return true;
               }
            return false;
         }
   }
