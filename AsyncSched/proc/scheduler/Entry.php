<?php
namespace proc\scheduler;
use proc\task;


/**
 * SchedQueue entry abstraction.
 * This class is considered an implementation detail internal to SchedQueue (@see Queue) and should not be used outside
 *
 * The scheduler doesn't care about concrete task types, states and other details, it only cares about task ID, ownership and sched-state.
 * ShedStates are differentiated into two main groups: new/shdAnalysis and ready/shdImport, with main difference between them being that for the first group
 * there is no SharedResUsage (pharms, med-dicts, etc) info yet available (it becomes available after analysis) and that this group is usually conflict-free (all tasks may be processed simultaneously).
 * In second (ready/shdImport) group however conflicting tasks are usual and expected, requiring at least strict (processing) order enforcement and sharedRes usage arbitration.
 *
 * 'completed' state is here just for consistency and to simplify some code, entries in this state meant to be dequeued ASAP
 *
 * shdAnalysis & shdImport states are virtual, they're only valid inside scheduler runtime
 * and doesn't necessarily imply that task is currently being processed, only that it has been scheduled for processing (e.g. published to workers exchange)
 */
final class Entry
   {
      const STATE_NEW          = 'new';
      const STATE_SHD_ANALYSIS = 'shd_analysis';   //scheduled for analysis (published to workers exchange)

      const STATE_READY        = 'ready';
      const STATE_SHD_IMPORT   = 'shd_import';     //scheduled for import (published to workers exchange)

      /**
       * done / error / cfg_required
       * In any of the above cases scheduler is done with that task
       */
      const STATE_COMPLETED    = 'completed';



      /** @var task\Task   Underlying task this entry represents */
      private $_task = NULL;

      /** @var string      Task processing (scheduling) state. One of self::STATE_* constants */
      private $_state = NULL;



      public function __construct(task\Task $task)
         {
            $this->_task=$task;
            $this->_evalTaskState();
         }



      public function getID()
         {
            return $this->_task->getID();
         }

      public function getOwnerID()
         {
            return $this->_task->getOwnerID();
         }

      public function getState()
         {
            return $this->_state;
         }


      public function isInState($state)
         {
            return is_array($state)? in_array($this->_state, $state) : ($this->_state==$state);
         }

      public function isScheduled()
         {
            return $this->isInState([self::STATE_SHD_ANALYSIS, self::STATE_SHD_IMPORT]);
         }

      public function isCompleted()
         {
            return $this->isInState(self::STATE_COMPLETED);
         }


      /** @return task\Task */
      public function getTask()
         {
            return $this->_task;
         }

      /**
       * Returns abstracted/generalized shared resource usage info
       * @return array  [resourceIdent => requiresExclusiveAccess] or NULL if SRUD is missing
       */
      public function getSharedResUsageDeclaration()
         {
            return empty($sru=$this->_task->getSharedResUsageDeclaration())? NULL : $sru->flatten();
         }



      /**
       * Meant to be called when underlying task is updated (e.g. response from worker arrived)
       * @param task\Task $task
       * @return Entry
       */
      public function syncToUpdatedTask(task\Task $task)
         {
            if($task->getID()!=$this->_task->getID()) throw new exception\DomainException(
               'syncToUpdatedTask() called with mismatching task ('.$this->_task->getID().' -> '.$task->getID().')'
            );

            $this->_task=$task;
            $this->_evalTaskState();
            return $this;
         }



      /**
       * Change entry schedState according to given graph and current state
       * @param array $graph  Just [fromState => toState] map atm
       * @return bool         Whether or not current state matched an entry in $graph
       */
      public function gotoState(array $graph)
         {
            $found=false;
            foreach($graph as $fromState=>$toState)
               {
                  if($this->isInState($fromState))
                     {
                        $this->_state=$toState;
                        $found=true;
                        break;
                     }
               }
            return $found;
         }

      private function _evalTaskState()
         {
            switch($this->_task->getState())
               {
                  //Scheduler doen't care differentiating between done/error/cfg_req states, they're all terminal.
                  //There remains nothing to do with such tasks, at least until user's intervention (and subsequent task state change and re-publish)

                  //However it may be useful to have those states in task instances (and then potentially in msg-broker routes)
                  //to implement error reporting and user-notifications about files requiring attention (e.g. unmapped pharmacy, un-cfgd file, broken file, etc)

                  case task\Task::STATE_NEW:
                     $this->_state=self::STATE_NEW;
                     break;
                  case task\Task::STATE_READY:
                     $this->_state=self::STATE_READY;
                     break;
                  case task\Task::STATE_DONE:
                  case task\Task::STATE_ERROR:
                  case task\Task::STATE_DCFG_REQ:
                  case task\Task::STATE_PCFG_REQ:
                     $this->_state=self::STATE_COMPLETED;
                     break;
                  default:
                     throw new exception\IllegalTaskStateException($this->_task);
                     //no tasks in transitional states (e.g. analyzing or importing) should ever appear here
               }
         }
   }
