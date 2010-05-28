<?php
return new WebSocketOverCOMET;
class WebSocketOverCOMET extends AsyncServer
{
 const IPCPacketType_C2S = 0x01;
 const IPCPacketType_S2C = 0x02;
 const IPCPacketType_POLL = 0x03;
 public $IpcTransSessions = array();
 public $wss;
 public $ipcId;
 /* @method init
    @description Constructor.
    @return void
 */
 public function init()
 {
  Daemon::addDefaultSettings(array(
   'mod'.$this->modname.'enable' => 0,
   'mod'.$this->modname.'ipcpath' => '/tmp/WsOverComet-%s.sock',
  ));
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
   $this->wss = Daemon::$appResolver->getInstanceByAppName('WebSocketServer');
  }
 }
 /* @method onReady
    @description Called when the worker is ready to go.
    @return void
 */
 public function onReady()
 {
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {   
   $this->ipcId = sprintf('%x',crc32(Daemon::$worker->pid.'-'.microtime(TRUE)));
   $this->bindSockets('unix:'.sprintf(Daemon::$settings['mod'.$this->modname.'ipcpath'],$this->ipcId),0,FALSE);
   $this->enableSocketEvents();
  }
 }
 /* @method connectIPC
    @description Establish connection with the given application instance of WebSocketOverCOMET.
    @param string ID
    @return integer connId
 */
 public function connectIPC($id)
 {
  if (isset($this->IpcTransSessions[$id])) {return $this->IpcTransSessions[$id];}
  $connId = $this->connectTo('unix:'.sprintf(Daemon::$settings['mod'.$this->modname.'ipcpath'],basename($id)));
  if (!$connId) {return FALSE;}
  $this->sessions[$connId] = new WebSocketOverCOMET_IPCTransSession($connId,$this);
  $this->sessions[$connId]->ipcId = $id;
  $this->IpcTransSessions[$id] = $connId;
  return $connId;
 }
 /* @method onAccepted
    @description Called when new connection is accepted.
    @param integer Connection's ID.
    @param string Address of the connected peer.
    @return void
 */
 public function onAccepted($connId,$addr)
 {
  $this->sessions[$connId] = new WebSocketOverCOMET_IPCRecvSession($connId,$this);
 }
 /* @method beginRequest
    @description Creates Request.
    @param object Request.
    @param object Upstream application instance.
    @return object Request.
 */
 public function beginRequest($req,$upstream)
 {
  if (!Daemon::$settings['mod'.$this->modname.'enable']) {return $req;}
  return new WebSocketOverCOMET_Request($this,$upstream,$req);
 }
}
class WebSocketOverCOMET_IPCRecvSession extends SocketSession
{
 /* @method init
    @description Constructor.
    @return void
 */
 public function init()
 {
 }
 /* @method stdin
    @description Called when new data recieved.
    @param string New data.
    @return void
 */
 public function stdin($buf)
 {
  $this->buf .= $buf;
  start:
  $l = strlen($this->buf);
  if ($l < 6) {return;} // not enough data yet.
  extract(unpack('Ctype/Chlen/Nblen',binarySubstr($this->buf,0,6)));
  if ($l < 6+$hlen+$blen)  {return;} // not enough data yet.
  $header = binarySubstr($this->buf,6,$hlen);
  $body = binarySubstr($this->buf,6+$hlen,$blen);
  $this->buf = binarySubstr($this->buf,6+$hlen+$blen);
  list($reqId,$authKey) = explode('.',$header);
  if (isset($this->appInstance->queue[$reqId]->downstream) && $this->appInstance->queue[$reqId]->authKey == $authKey)
  {
   if ($type === WebSocketOverCOMET::IPCPacketType_C2S)
   {
    $this->appInstance->queue[$reqId]->downstream->onFrame($body,WebSocketServer::STRING);
    $this->appInstance->queue[$reqId]->atime = time();
   }
   elseif ($type === WebSocketOverCOMET::IPCPacketType_S2C)
   {
    if (isset($this->appInstance->polling[$reqId.'.'.$authKey]))
    {
     foreach ($this->appInstance->polling[$reqId.'.'.$authKey] as $pollReqId)
     {
      if (isset($this->appInstance->queue[$pollReqId]))
      {
       $this->appInstance->polling[$pollReqId]->out($body);
       $this->appInstance->polling[$pollReqId]->finish();
      }
     }
    }
   }
   elseif ($type === WebSocketOverCOMET::IPCPacketType_POLL)
   {
    $this->appInstance->queue[$reqId]->polling[] = $this->connId;
    $this->appInstance->queue[$reqId]->flushBufferedPackets();
    $this->appInstance->queue[$reqId]->atime = time();
   }
  }
  goto start;
 }
}
class WebSocketOverCOMET_IPCTransSession extends SocketSession
{
 /* @method onFinish
    @description Called when the session finished.
    @return void
 */
 public function onFinish()
 {
  unset($this->appInstance->sessions[$this->connId]);
  unset($this->appInstance->IpcTransSessions[$this->ipcId]);
 }
}
class WebSocketOverCOMET_Request extends Request
{
 public $inited = FALSE;
 public $authKey;
 public $downstream;
 public $callbacks = array();
 public $polling = array();
 public $bufferedPackets = array();
 public $type;
 public $atime;
 /* @method init
    @description Constructor.
    @return void
 */
 public function init()
 {
  if (isset($this->attrs->get['_pull'])) {$this->type = 'pull';}
  elseif (isset($this->attrs->get['_poll']) && isset($this->attrs->get['_init'])) {$this->type = 'pollInit';}
  elseif (isset($this->attrs->get['_poll'])) {$this->type = 'poll';}
  else {$this->type = 'push';}
 }
 /* @method run
    @description Called when request iterated.
    @return integer Status.
 */
 public function run()
 {
  if ($this->type === 'push')
  {
   $ret = array();
   $e = explode('.',self::getString($_REQUEST['_id']),2);
   if (sizeof($e) != 2) {$ret['error'] = 'Bad cookie.';}
   elseif (!isset($_REQUEST['data'])) {$ret['error'] = 'No data.';}
   elseif (!is_string($_REQUEST['data'])) {$ret['error'] = 'No data.';}
   elseif ($connId = $this->appInstance->connectIPC(basename($e[0])))
   {
    $this->appInstance->sessions[$connId]->write(pack('CCN',WebSocketOverCOMET::IPCPacketType_C2S,strlen($e[1]),strlen($_REQUEST['data'])).$e[1]);
    $this->appInstance->sessions[$connId]->write($_REQUEST['data']);
   }
   else {$ret['error'] = 'IPC error.';}
   echo json_encode($ret);
   return Request::DONE;
  }
  elseif ($this->type === 'pull')
  {
   if (!$this->inited)
   {
    $this->authKey = sprintf('%x',crc32(microtime()."\x00".$this->attrs->server['REMOTE_ADDR']));
    $this->header('Content-Type: text/html; charset=utf-8');
    $this->inited = TRUE;
    $this->out('<!--'.str_repeat('-',1024).'->'); // Padding
    $this->out('<script type="text/javascript"> WebSocket.onopen("'.$this->appInstance->ipcId.'.'.$this->idAppQueue.'.'.$this->authKey.'"); </script>'."\n");
    $appName = self::getString($_REQUEST['_route']);
    if (!isset($this->appInstance->wss->routes[$appName]))
    {
     if (isset(Daemon::$settings['logerrors']) && Daemon::$settings['logerrors']) {Daemon::log(__METHOD__.': undefined route \''.$appName.'\'.');}
     return Request::DONE;
    }
    if (!$this->downstream = call_user_func($this->appInstance->wss->routes[$appName],$this)) {return Request::DONE;}
   }
   $this->sleep(1);
  }
  elseif ($this->type === 'pollInit')
  {
   if (!$this->inited)
   {
    $this->authKey = sprintf('%x',crc32(microtime()."\x00".$this->attrs->server['REMOTE_ADDR']));
    $this->header('Content-Type: text/plain; charset=utf-8');
    $this->inited = TRUE;
    $appName = self::getString($_REQUEST['_route']);
    if (!isset($this->appInstance->wss->routes[$appName]))
    {
     if (isset(Daemon::$settings['logerrors']) && Daemon::$settings['logerrors']) {Daemon::log(__METHOD__.': undefined route \''.$appName.'\'.');}
     echo json_encode(array('error' => 404));
     return Request::DONE;
    }
    if (!$this->downstream = call_user_func($this->appInstance->wss->routes[$appName],$this))
    {
     echo json_encode(array('error' => 403));
     return Request::DONE;
    }
    echo json_encode(array('id' => $this->appInstance->ipcId.'.'.$this->idAppQueue.'.'.$this->authKey));
    $this->atime = time();
    $this->finish();
   }
   if ($this->atime < time()-10)
   {
    if (isset($this->downstream))
    {
     $this->downstream->onFinish();
     unset($this->downstream);
    }
    return 1;
   }
   $this->sleep(2);
  }
  elseif ($this->type === 'poll')
  {
   if (!$this->inited)
   {
    $this->header('Content-Type: text/plain; charset=utf-8');
    $this->inited = TRUE;
    $ret = array();
    $e = explode('.',self::getString($_REQUEST['_id']),2);
    if (sizeof($e) != 2) {$ret['error'] = 'Bad cookie.';}
    elseif ($connId = $this->appInstance->connectIPC(basename($e[0])))
    {
     $this->appInstance->sessions[$connId]->write(pack('CCN',WebSocketOverCOMET::IPCPacketType_POLL,strlen($e[1]),0));
    }
    else {$ret['error'] = 'IPC error.';}
    if (sizeof($ret))
    {
     echo json_encode($ret);
     return Request::DONE;
    }
    $this->reqIdAuthKey = $e[1];
    $this->appInstance->polling[$this->reqIdAuthKey] = $this->idAppQueue;
    $this->sleep(1);
   }
   $this->sleep(10);
  }
 }
 /* @method onAbort
    @description Called when the request aborted.
    @return void
 */
 public function onAbort()
 {
  if ($this->type !== 'pollInit')
  {
   if (isset($this->downstream))
   {
    $this->downstream->onFinish();
    unset($this->downstream);
   }
   $this->finish();
  }
 }
 /* @method onWrite
    @description Called when the connection is ready to accept new data.
    @return void
 */
 public function onWrite()
 {
  if ($this->type !== 'pollInit')
  {
   for ($i = 0,$s = sizeof($this->callbacks); $i < $s; ++$i) {call_user_func(array_shift($this->callbacks),$this);}
   if (is_callable(array($this->downstream,'onWrite'))) {$this->downstream->onWrite();}
  }
 }
 /* @method flushBufferedPackets()
    @description Flushes buffered packets (only for the long-polling method)
    @return void
 */
 public function flushBufferedPackets()
 {
  if (!sizeof($this->polling)) {return;}
  $h = $this->idAppQueue.'.'.$this->authKey;
  $packet = pack('CCN',WebSocketOverCOMET::IPCPacketType_S2C,strlen($h),strlen($packet))
  .$h.json_encode(array('ts' => microtime(TRUE),'packets' => $this->bufferedPackets));
  foreach ($this->polling as $p)
  {
   if (!isset($this->appInstance->sessions[$p])) {continue;}
   $this->appInstance->sessions[$connId]->write($packet);
  }
  for ($i = 0,$s = sizeof($this->callbacks); $i < $s; ++$i) {call_user_func(array_shift($this->callbacks),$this);}
  if (is_callable(array($this->downstream,'onWrite'))) {$this->downstream->onWrite();}
 }
 /* @method sendFrame
    @description Sends a frame.
    @param string Frame's data.
    @param integer Frame's type. See the constants.
    @param callback Optional. Callback called when the frame is recieved by client.
    @return boolean Success.
 */
 public function sendFrame($data,$type = 0x00,$callback = NULL)
 {
  if ($this->type === 'pollInit')
  {
   $this->bufferedPackets[] = array($type,$data);
   $this->flushBufferedPackets();
  }
  $this->out('<script type="text/javascript">WebSocket.onmessage('.json_encode($data).");</script>\n");
  if ($callback) {$this->callbacks[] = $callback;}
  return TRUE;
 }
 /* @method onFinish
    @description Called when the request finished.
    @return void
 */
 public function onFinish()
 {
  unset($this->appInstance->clients[$this->idAppQueue]);
 }
}