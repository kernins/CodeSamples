<?php
namespace proc\messaging\tasks;
use proc\task;


/**
 * Endpoint for task producers
 * Multiple task producers may co-exist simultaneously
 */
final class EndpointIngress extends EndpointAbstract
   {
      public function publish(task\Task $task)
         {
            $this->exchange->publish($this->createMessageForTask($task), self::MT_INGRESS);
            return $this;
         }


      /**
       * It is preferred to use batch publishing as scheduler has some optimizations for such case
       *
       * @param task\Task $task
       * @return EndpointIngress
       */
      public function publishBatchEnqueue(task\Task $task)
         {
            $this->exchange->publishBatchEnqueue($this->createMessageForTask($task), self::MT_INGRESS);
            return $this;
         }

      public function publishBatchPublish()
         {
            $this->channel->publishMessagesBatch();
            return $this;
         }
   }
