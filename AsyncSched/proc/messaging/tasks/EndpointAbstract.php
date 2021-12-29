<?php
namespace proc\messaging\tasks;
use proc\task, messaging;


/**
 * Tasks-domain IPC-messaging
 * Abstracts all AMQ exchange, queues and their relations (bindings) details
 * See notes.txt for further design details
 */
abstract class EndpointAbstract
   {
      /* messaging modes (public) */
      const MODE_TRANSIENT    = 0b00000000;  //non-durable messages, exchanges and queues (except when required by design)
      const MODE_PERSISTENT   = 0b00000001;  //non-durable messages, but durable exchanges and queues
      const MODE_DURABLE      = 0b00000011;  //all durable, implies MODE_PERSISTENT


      const EX_NAME           = 'import.tasks';

      /* message type, used in routing (private) */
      const MT_INGRESS        = 'ingress';
      const MT_SCHEDULED      = 'scheduled';
      const MT_PROCESSED      = 'processed';
      const MT_REFUSED        = 'refused';

      /* operation type, used in routing (private) */
      const OT_ANALYSIS       = 'analysis';
      const OT_IMPORT         = 'import';

      /* operation status, used in routing (private) */
      //const OS_SUCCESS        = 'success';
      //const OS_FAILURE        = 'failure';
      //currently unused



      /** @var messaging\amqp\ChannelWrapper */
      protected $channel=NULL;
      protected $mode=NULL;

      /** @var messaging\amqp\exchange\Topic */
      protected $exchange=NULL;



      public function __construct(messaging\amqp\ChannelWrapper $amqpChannel, $mode=NULL)
         {
            $this->channel=$amqpChannel;
            $this->mode=$mode===NULL? self::MODE_TRANSIENT : (int)$mode;

            $isPersistent=$this->mode & self::MODE_PERSISTENT;
            $this->exchange=new messaging\amqp\exchange\Topic(
               $this->channel,
               self::EX_NAME,
               false,            //passive
               $isPersistent,    //durable
               !$isPersistent    //autoDel
            );
         }



      public function waitForEventsAsync($maxSec, $maxUsec)
         {
            return $this->channel->waitForEventsAsync($maxSec, $maxUsec);
         }



      /**
       * @param task\Task $task
       * @return messaging\amqp\message\Outgoing
       */
      protected function createMessageForTask(task\Task $task)
         {
            $msg=new messaging\amqp\message\Outgoing(json_encode($task, JSON_PRESERVE_ZERO_FRACTION), $this->mode&self::MODE_DURABLE);
            $msg->setContentType('application/json')->setContentEncoding('UTF-8');
            return $msg;
         }

      /**
       * @param messaging\amqp\message\Incoming $msg
       * @return task\Task
       */
      protected function getTaskFromMessage(messaging\amqp\message\Incoming $msg)
         {
            return task\Task::newInstanceFromJSON($msg->getBody());
         }
   }
