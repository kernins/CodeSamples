<?php

function __cli_setup_logger(\utils\cli\CmdArgs $cmdArgs)
   {
      $logLvl=[\proc\logger\FD::LVL_SRC_DEFAULT => $cmdArgs->hasOpt('debug')? (int)$cmdArgs->getOpt('debug'):\proc\logger\FD::LVL_NONE];
      if($cmdArgs->hasOpt('debug-hndl'))  $logLvl[\proc\logger\FD::SRC_HNDL]=(int)$cmdArgs->getOpt('debug-hndl');
      if($cmdArgs->hasOpt('debug-rdr'))   $logLvl[\proc\logger\FD::SRC_RDR]=(int)$cmdArgs->getOpt('debug-rdr');
      if($cmdArgs->hasOpt('debug-adptr')) $logLvl[\proc\logger\FD::SRC_ADPTR]=(int)$cmdArgs->getOpt('debug-adptr');
      if($cmdArgs->hasOpt('debug-trg'))   $logLvl[\proc\logger\FD::SRC_TRG]=(int)$cmdArgs->getOpt('debug-trg');
      if(!isset($logLvl[\proc\logger\FD::SRC_ADPTR]) && isset($logLvl[\proc\logger\FD::SRC_RDR])) $logLvl[\proc\logger\FD::SRC_ADPTR]=$logLvl[\proc\logger\FD::SRC_RDR];

      return new \proc\logger\FD(
         [\proc\logger\FD::LVL_ERR=>STDERR, \proc\logger\FD::LVL_WARN=>STDERR, \proc\logger\FD::TRG_LVL_DEFAULT=>STDOUT],
         $logLvl,
         ($cmdArgs->hasNEOpt('debug-ex-prev')? \proc\logger\FD::EX_DUMP_PREV:0) | ($cmdArgs->hasNEOpt('debug-ex-trace')? \proc\logger\FD::EX_DUMP_TRACE:0)
      );
   }

function __cli_proc_setgiduid(\utils\cli\CmdArgs $cmdArgs, \proc\ILogger $logger)
   {
      if($cmdArgs->hasOpt('group'))
         {
            $tmp=\utils\cli\Util::setGID($cmdArgs->getOpt('group')); //will throw on failure
            $logger->info('Changed GID to '.$tmp.' ['.$cmdArgs->getOpt('group').']');
         }
      if($cmdArgs->hasOpt('user'))
         {
            $tmp=\utils\cli\Util::setUID($cmdArgs->getOpt('user')); //will throw on failure
            $logger->info('Changed UID to '.$tmp.' ['.$cmdArgs->getOpt('user').']');
         }
   }
