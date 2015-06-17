<?php
##
$pid = pcntl_fork();
if($pid == -1){
   exit("\033[40;31mFatal!\n\033[0m");
}else if($pid > 0){
   exit;
}else{
   $pid = pcntl_fork();
   if($pid == -1){
      exit("\033[40;31mFatal!\n\033[0m");
   }else if($pid > 0){
       exit("\033[40;32mSuccess!\033[0m\n\033[42;37mHELLO WORLD!\n\033[0m");
   }else{
         posix_setsid();
   }
}

if(strtolower(PHP_OS) == "winnt" )exit("WINNT System isn't supported!");
if(version_compare(PHP_VERSION,'5.3','<'))exit("PHP version must be greater than 5.3 or equal to 5.3");

$readMap = [];//监测fd的可读性状态
$writeMap = NULL;//监测fd的可写入性状态
$errMap = NULL;//监测fd的错误性状态
$event = [];//时间监听容器

//处理输入流
$dealInput = function ($fd){

	global $event;
    //连接断开  
	if(fgets($event['fd'][$fd]) == '' && feof($event['fd'][$fd])){
		fclose($event['fd'][$fd]);//关闭fd
		unset($event['fd'][$fd]);//删除监听容器的fd
		echo "connection lost ".$fd;
		return FALSE;
	}

	$pid = posix_getpid();
	//对某个端写入数据
    fwrite($event['fd'][$fd], 'Process ' .$pid.' Say:The local time is ' . date('n/j/Y g:i a') . "\r\n");
};

//监听socket连接
$accept = function ($fd){

	global $event,$writeMap,$errMap,$socket;
    //并行处理端连接

	$conn = stream_socket_accept($event['fd'][$fd],0);

	if( ! $conn){echo posix_getpid();return FALSE;}
    //设置成非阻塞模式
	stream_set_blocking($conn,FALSE);

    //存储fd到时间监听容器
    $event['fd'][(int)$conn] = $conn;
	//可读性状态后回调方法
	$event[(int)$conn]['callback'] = $GLOBALS['dealInput'];
};

$alarms = [];//加入定时任务
$ttyClose = FALSE;//tty是否关闭
//定时检查tty是否关闭，关闭后重定向标准输出和标准错误输出
$checkTty = function($p)use(&$alarms,&$ttyClose){
     
	 global $STDERR,$STDOUT;
	 if( ! $ttyClose){
	 
		 if( ! posix_isatty(STDOUT)){
			fclose(STDOUT);
			$STDOUT = fopen("/dev/null","a+");
			$ttyClose = TRUE;
			if(isset($alarms['checkTty']))unset($alarms['checkTty']);
		 }
		 if( ! posix_isatty(STDERR)){
			fclose(STDERR);
			$STDERR = fopen("/dev/null","a+");
			$ttyClose = TRUE;
			if(isset($alarms['checkTty']))unset($alarms['checkTty']);
		 }
     }
	 
};
//定时任务执行器
$checkAlarm = function() use (&$alarms){

	if(empty($alarms))return FALSE;

    $cur = time();
    foreach($alarms as $k => $a){
	   if($a['start'] + $a['per'] <= $cur){
		   if(is_array($a) && is_callable($a['func'])){
				$a['func']($a['param'] ?:[]);
		   }
		   if($a['once']){
				unset($alarms[$k]);
		   }else{
		        $alarms[$k]['start'] = $cur;
		   }
	   }
	}
	pcntl_alarm(1);
};
//卸载信号触发器
pcntl_signal(SIGALRM,SIG_IGN);
//安装信号触发器
pcntl_signal(SIGALRM,$checkAlarm,FALSE);
//定时任务规范
$alarms['checkTty'] = ['func' => $checkTty, 'once' => FALSE, 'param' => NULL, 'start' => time(), 'per' => 1];

$socket = stream_socket_server("tcp://0.0.0.0:8011", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
stream_set_blocking($socket,FALSE);

$fork = function()use ($accept,$socket,$checkAlarm){
	
       global $event;
       //创建子进程
	   $pid = pcntl_fork();
	   if($pid > 0){
	      return $pid;
	   }elseif($pid === 0){
		  pcntl_signal(SIGALRM,SIG_IGN);
		  pcntl_signal(SIGALRM, $checkAlarm, TRUE);
		  pcntl_alarm(1);
          //设置子进程的运行身份，名字
          $uInfo = posix_getpwnam("nobody");
		  //posix_setuid($uInfo['uid']);
		  cli_set_process_title("MyServer-".posix_getpid());
          
	      $event['fd'][(int)$socket]  = $socket;
	      $event[(int)$socket]['callback']  = $accept;

	      loop();//开启循环事件监听
	   }
       return FALSE;
};

$createWorker = function($process = 10, $socket = NULL) use ($fork,$checkAlarm){
	 
     $workers = [];
	 pcntl_alarm(1);
	 while(TRUE){
	    if(count($workers) < $process){
		   $pid = $fork();
		   if($pid > 0)$workers[$pid] = $pid;
		}else{
		   //监控子进程退出，退出后重启子进程
		   if($cid = pcntl_waitpid(-1, $status, WNOHANG | WUNTRACED)){
		      unset($workers[$cid]);
			  print_r($status);
		   }
		}
		sleep(1);
		pcntl_signal_dispatch();
	 }
};

if( ! $socket){
    echo "$errstr ($errno)<br />\n";
}else{
	$createWorker(3);
}
//事件循环监听
function loop(){

   global $event,$writeMap,$errMap;

   end:
   while(TRUE){
	   $readMap = $event['fd'];
	   if( ! ($num = @stream_select($readMap, $writeMap, $errMap, 1))){
	      if($num === 0){//这里是超时
		     //echo "TIME OUT";
		  }elseif($num === FALSE){//这里是异常发生
		     //echo "throw an exception!";
		  }
		  //执行信号操作
		  pcntl_signal_dispatch();
		  goto end;//循环监听，不停止
	   }
	   //监测的所有fd中有可读状态改变，处理回调
	   foreach ($readMap as $r) {
	       $event[(int)$r]['callback']((int)$r);
	   }
	   //执行信号操作
	   pcntl_signal_dispatch();
   }
}
