#!/usr/bin/php
<?php
require_once __DIR__.DIRECTORY_SEPARATOR.'_init.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'_cli_helpers.php';


/*
 * ONLY SINGLE instance is allowed per data and/or AMQ domain!
 *
 * Run opts
 *    --debug=int             global debug lvl, or default if some src-specific lvls are specified
 *    --debug-hndl=int        hndl debug
 *
 *    --debug-ex-prev
 *    --debug-ex-trace
 *
 *    --task-rcvr-limit=int   Tasks recovery limit, mainly for debugging purposes
 *
 *    --user
 *    --group
 */


$cmdOpts=new \utils\cli\CmdArgs($argv);
$procTitle='Import::SCHED';

try
   {
      $logger=__cli_setup_logger($cmdOpts);
      __cli_proc_setgiduid($cmdOpts, $logger);

      if(!\utils\cli\Util::createPidFile($pidFile=sys_get_temp_dir().DIRECTORY_SEPARATOR.\utils\UtilString::translit($procTitle? $procTitle:__FILE__).'.pid'))
         throw new \AnotherInstanceRunningException('Another instance still running'); //given path exists and contains valid and running pid
      $logger->info('My PID is stored in "'.$pidFile.'"'.PHP_EOL);
      if(!empty($procTitle)) cli_set_process_title($procTitle);


      //instantiating scheduler first, so all queues will be set up and no messages lost
      $scheduler=new \proc\scheduler\Scheduler(
         new \proc\messaging\tasks\EndpointScheduler(
            \messaging\Manager::getInstance()->getChannel(),
            \proc\messaging\tasks\EndpointScheduler::MODE_TRANSIENT
         ),
         $logger
      );

      /** APP-SPECIFIC CRASH-RECOVERY CODE REMOVED **/

      $logger->perf('Starting at '.date('d.m.Y H:i:s').'...'.PHP_EOL);
      $scheduler->run();
   }
catch(\AnotherInstanceRunningException $ex) {$logger->info($ex); /*will be suppressed in production until logLvl>=3, still allows to monitor errs and warns w/o spam*/}
catch(\Exception $ex) {fwrite(STDERR, 'Fatal error: PID '.getmypid().': '.\web\engine\ErrorHandler::getErrorMessage($ex, true, true, true));}
