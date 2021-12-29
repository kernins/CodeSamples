<?php
namespace proc\messaging\tasks;
use messaging, proc\task;


/**
 * Abstract endpoint for workers
 * Multiple workers may co-exist simultaneously, incoming tasks will be distributed in round-robin manner
 * Worker consumers have QoS of max-unacked-cnt=1, so no further tasks will be delivered to busy worker until it is done with current one
 */
abstract class EndpointWorkerAbstract extends EndpointAbstract
   {
      const OP_TYPE = NULL;


      /** @var messaging\amqp\queue\Generic  Queue for incoming tasks */
      protected $queue=NULL;


      //May also need per-queue message TTL arg
      //May also need queue-TTL arg in future
      public function __construct(messaging\amqp\ChannelWrapper $amqpChannel, $mode=NULL)
         {
            parent::__construct($amqpChannel, $mode);

            //limit delivery to 1 unacked msg per consumer
            //global=false, apply separately to each new consumer (rabbitMQ)
            $this->channel->setQoS(0, 1, /*global*/false);


            $qArgs=new messaging\amqp\helper\arguments\rabbitmq\Queue();
            $qArgs->setDLX(self::EX_NAME, self::MT_REFUSED.messaging\amqp\exchange\Topic::BND_SEP.static::OP_TYPE);
            //$qArgs->setTTL(15000);

            $isPersistent=$this->mode & self::MODE_PERSISTENT;
            $this->queue=new messaging\amqp\queue\Generic(
               $this->channel,
               self::EX_NAME.'.worker.'.static::OP_TYPE,
               false,            //passive
               $isPersistent,    //durable
               false,            //autoDel
               false,            //exclusive, wrk-queues must stay alive due to unsupported dead-lettering of pending msgs on queue deletion
               $qArgs
            );
            $this->queue->bindTo($this->exchange, self::MT_SCHEDULED.messaging\amqp\exchange\Topic::BND_SEP.static::OP_TYPE);
         }


      public function initConsumer(callable $hndl)
         {
            $this->queue->consumerRegister(function(messaging\amqp\message\Incoming $msg) use($hndl) {
               if($hndl($this->getTaskFromMessage($msg))) $msg->ack();
               else $msg->reject(/*requeue*/false); //will go to DLX and then return to scheduler
            }, /*noLocal*/true, /*noAck*/false, /*exclusive*/false);
         }


      public function publish(task\Task $task/*, $opStatus*/)
         {
            $this->exchange->publish($this->createMessageForTask($task), self::MT_PROCESSED.messaging\amqp\exchange\Topic::BND_SEP.static::OP_TYPE/*.messaging\amqp\exchange\Topic::BND_SEP.$opStatus*/);
            return $this;
         }
   }
