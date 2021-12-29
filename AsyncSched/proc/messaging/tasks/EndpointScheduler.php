<?php
namespace proc\messaging\tasks;
use proc\task, messaging;


/**
 * Endpoint for task-scheduler
 * There may be only one scheduler process at any given moment
 */
final class EndpointScheduler extends EndpointAbstract
   {
      /** @var messaging\amqp\queue\Generic  Queue for tasks to be scheduled */
      protected $queueIngress=NULL;
      /** @var messaging\amqp\queue\Generic  Queue for responses from workers (carrying updated task def) */
      protected $queueProcessed=NULL;
      /** @var messaging\amqp\queue\Generic  Queue for refused (rejected, timed-out or otherwise dead-lettered) tasks */
      protected $queueRefused=NULL;



      public function __construct(messaging\amqp\ChannelWrapper $amqpChannel, $mode=NULL)
         {
            parent::__construct($amqpChannel, $mode);

            $isPersistent=$this->mode & self::MODE_PERSISTENT;
            $this->queueIngress=new messaging\amqp\queue\Generic(
               $this->channel,
               self::EX_NAME.'.sched.ingress',
               false,            //passive
               $isPersistent,    //durable
               false,            //autoDel
               !$isPersistent    //exclusive
            );
            $this->queueProcessed=new messaging\amqp\queue\Generic(
               $this->channel,
               self::EX_NAME.'.sched.procd',
               false,            //passive
               $isPersistent,    //durable
               false,            //autoDel
               !$isPersistent    //exclusive
            );
            $this->queueRefused=new messaging\amqp\queue\Generic(
               $this->channel,
               self::EX_NAME.'.sched.refused',
               false,            //passive
               $isPersistent,    //durable
               false,            //autoDel
               !$isPersistent    //exclusive
            );

            //TODO: helper to build routing-keys
            $this->queueIngress->bindTo($this->exchange, self::MT_INGRESS.messaging\amqp\exchange\Topic::BND_SEP.messaging\amqp\exchange\Topic::BND_GLOB_ANY_OR_NONE);
            $this->queueProcessed->bindTo($this->exchange, self::MT_PROCESSED.messaging\amqp\exchange\Topic::BND_SEP.messaging\amqp\exchange\Topic::BND_GLOB_ANY_OR_NONE);
            $this->queueRefused->bindTo($this->exchange, self::MT_REFUSED.messaging\amqp\exchange\Topic::BND_SEP.messaging\amqp\exchange\Topic::BND_GLOB_ANY_OR_NONE);
         }



      public function initConsumer(callable $hndlIngress, callable $hndlProcessed, callable $hndlRefused)
         {
            $isPersistent=$this->mode & self::MODE_PERSISTENT;
            $this->queueIngress->consumerRegister(function(messaging\amqp\message\Incoming $msg) use($isPersistent, $hndlIngress) {
               $hndlIngress($this->getTaskFromMessage($msg));
               if($isPersistent) $msg->ack(); //no sense in rejecting
            }, /*noLocal*/true, /*noAck*/!$isPersistent, /*exclusive*/true);
            $this->queueProcessed->consumerRegister(function(messaging\amqp\message\Incoming $msg) use($isPersistent, $hndlProcessed) {
               $hndlProcessed($this->getTaskFromMessage($msg));
               if($isPersistent) $msg->ack(); //no sense in rejecting
            }, /*noLocal*/true, /*noAck*/!$isPersistent, /*exclusive*/true);
            $this->queueRefused->consumerRegister(function(messaging\amqp\message\Incoming $msg) use($isPersistent, $hndlRefused) {
               $hndlRefused($this->getTaskFromMessage($msg), true); //rejected
               if($isPersistent) $msg->ack(); //no sense in rejecting
            }, /*noLocal*/true, /*noAck*/!$isPersistent, /*exclusive*/true);

            //unroutable handler
            $this->exchange->setReturnHandler(function(messaging\amqp\message\Incoming $msg, $replyCode, $replyText, $routingKey) use($hndlRefused) {
               $hndlRefused($this->getTaskFromMessage($msg), false); //returned
            });
         }


      public function publish(task\Task $task, $opType)
         {
            //Scheduler messages must be published as mandatory, so they will be returned if unroutable (no matching queues exist)
            $this->exchange->publish($this->createMessageForTask($task), self::MT_SCHEDULED.messaging\amqp\exchange\Topic::BND_SEP.$opType, /*mandatory*/true);
            return $this;
         }
   }
