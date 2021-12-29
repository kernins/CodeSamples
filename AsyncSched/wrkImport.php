#!/usr/bin/php
<?php
require_once __DIR__.DIRECTORY_SEPARATOR.'_init.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'_cli_helpers.php';


/*
 * Multiple instances allowed by using unique --inst-id.
 * Horizontal scaling possible, but no partitioning rules implemented atm
 *
 * Run opts
 *    --debug=int          global debug lvl, or default if some src-specific lvls are specified
 *    --debug-hndl=int     hndl debug
 *    --debug-rdr=int      reader debug, also adptr if latter not specified explicitly
 *    --debug-adptr=int    excplicit adptr debug
 *    --debug-trg=int      trg debug
 *
 *    --debug-ex-prev
 *    --debug-ex-trace
 *
 *    --inst-id=int        worker ID, use unique IDs to run multiple instances simultaneously
 *
 *    --max-mem=int        SafeGuard memory limit in MiB. Required
 *    --max-time=int       SafeGuard runtime limit in sec. Optional
 *    --max-tasks=int      SafeGuard tasks limit. Optional
 *
 *    --user               Run as UID or uName
 *    --group              Run as GID or gName
 */


$cmdOpts=new \utils\cli\CmdArgs($argv);
$procTitle='Import::WRK-I';

try
   {
      $logger=__cli_setup_logger($cmdOpts);
      __cli_proc_setgiduid($cmdOpts, $logger);


      if(!$cmdOpts->hasOpt('max-mem')) throw new \InvalidArgumentException('No safeguard memory limit given');
      $workerID=$cmdOpts->getNEOpt('inst-id');

      if(!\utils\cli\Util::createPidFile($pidFile=sys_get_temp_dir().DIRECTORY_SEPARATOR.\utils\UtilString::translit($procTitle? $procTitle:__FILE__).(empty($workerID)? '':'_'.$workerID).'.pid'))
         throw new \AnotherInstanceRunningException('Another instance still running'); //given path exists and contains valid and running pid
      $logger->info('My PID is stored in "'.$pidFile.'"'.PHP_EOL);
      if(!empty($procTitle)) cli_set_process_title($procTitle.(empty($workerID)? '':' #'.$workerID));


      $wrk=new \proc\worker\WorkerImport(
         new \proc\messaging\tasks\EndpointWorkerImport(
            \messaging\Manager::getInstance()->getChannel(),
            \proc\messaging\tasks\EndpointWorkerImport::MODE_TRANSIENT
         ),
         $logger
      );
      $wrk->setSafeguard(new \proc\worker\Safeguard(
         $cmdOpts->getOpt('max-mem'),
         $cmdOpts->getNEOpt('max-time'),  //non-empty or null
         $cmdOpts->getNEOpt('max-tasks')  //non-empty or null
      ));

      $logger->perf('Starting at '.date('d.m.Y H:i:s').'...'.PHP_EOL);
      $wrk->run();
   }
catch(\AnotherInstanceRunningException $ex) {$logger->info($ex); /*will be suppressed in production until logLvl>=3, still allows to monitor errs and warns w/o spam*/}
catch(\Exception $ex) {fwrite(STDERR, 'Fatal error: PID '.getmypid().': '.\web\engine\ErrorHandler::getErrorMessage($ex, true, true, true));}
