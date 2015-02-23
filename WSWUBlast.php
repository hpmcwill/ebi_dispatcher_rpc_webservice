<?php
  /* EMBL-EBI WSWUBlast service proxy for WU-BLAST (SOAP).
   *
   * For WSWUBlast see:
   * http://www.ebi.ac.uk/Tools/webservices/services/archive/sss/wswublast
   * https://www.biocatalogue.org/services/1927
   * For WU-BLAST (SOAP) see:
   * http://www.ebi.ac.uk/Tools/webservices/services/sss/wu_blast_soap
   */

  // Back-end class to map WSWUBlast requests to WU-BLAST (SOAP)
class WuBlastSoap {
  // Service WSDL
  private $wsdlUrl = 'http://www.ebi.ac.uk/Tools/services/soap/wublast?wsdl';
  // Service proxy.
  private $srvProxy;

  // Get HTTP User-agent string.
  private function getUserAgent($opName = 'unknown') {
    $clientVersion = trim(substr('$Revision: 1 $', 11), ' $');
    if($clientVersion == '') $clientVersion = '0';
    $sysname  = php_uname('s');
    $userAgent = 'WSWUBlast/' . $clientVersion . ' (' . get_class($this) . '; ' . $sysname . '; ' . $opName . ') PHP-SOAP/' . phpversion();
    return $userAgent;
  }

  // Get a service proxy
  private function serviceProxyConnect($opName = 'unknown') {
    // Check for SoapClient
    if(!class_exists('SoapClient')) {
      throw new Exception('SoapClient class cannot be found.');
    }
    // Get service proxy
    if($this->srvProxy == null) {
      $options = array(
		       'compression' => true,
		       #'proxy_host' => '',
		       #'proxy_port' => 8080,
		       #'trace' => $this->trace,
		       'user_agent' => $this->getUserAgent($opName)
		       );
      $this->srvProxy = new SoapClient($this->wsdlUrl, $options);
    }
  }

  // Wait for job to finish.
  private function clientPoll($jobId) {
    $this->serviceProxyConnect('clientPoll');
    $status = 'PENDING';
    while(strcmp($status, 'PENDING') == 0 || strcmp($status, 'RUNNING') == 0) {
      sleep(5);
      $res = $this->srvProxy->getStatus(array('jobId' => $jobId));
      $status = $res->status;
    }
  }

  // runWUBlast(params content)
  public function runWUBlast($params, $content) {
    // BLAST program -> Sequence type mapping
    $seqTypeMap = array(
                       'blastn' => 'dna',
		       'blastp' => 'protein',
		       'blastx' => 'dna',
		       'tblastn' => 'protein',
		       'tblastx' => 'dna'
                       );
    // Map parameters
    $tool_params = array();
    if(array_key_exists('program', $params)) {
      $tool_params['program'] = $params['program'];
      $tool_params['stype'] = $seqTypeMap[strtolower($params['program'])];
    }
    if(array_key_exists('database', $params)) {
      $tool_params['database'] = explode(' ', $params['database']);
    }
    if(array_key_exists('sort', $params)) {
      $tool_params['sort'] = $params['sort'];
    }
    if(array_key_exists('filter', $params)) {
      $tool_params['filter'] = $params['filter'];
    }
    if(array_key_exists('matrix', $params)) {
      $tool_params['matrix'] = $params['matrix'];
    }
    if(array_key_exists('numal', $params)) {
      $tool_params['alignments'] = $params['numal'];
    }
    if(array_key_exists('scores', $params)) {
      $tool_params['scores'] = $params['scores'];
    }
    if(array_key_exists('topcombon', $params)) {
      $tool_params['topcombon'] = $params['topcombon'];
    }
    if(array_key_exists('exp', $params)) {
      if($params['exp'] >= 1.0) {
        $tool_params['exp'] = sprintf('%.1f', $params['exp']);
      }
      else {
        $tool_params['exp'] = sprintf('%.0e', $params['exp']);
      }
    }
    if(array_key_exists('echofilter', $params)) {
      $tool_params['viewfilter'] = $params['echofilter'];
    }
    if(array_key_exists('stats', $params)) {
      $tool_params['stats'] = $params['stats'];
    }
    if(array_key_exists('strand', $params)) {
      $tool_params['strand'] = $params['strand'];
    }
    if(array_key_exists('sensitivity', $params)) {
      $tool_params['sensitivity'] = $params['sensitivity'];
    }
    if(array_key_exists('appxml', $params)) {
      if(strcasecmp($params['appxml'], 'yes') == 0 ||
         strcasecmp($params['appxml'], '1') == 0 ||
         strcasecmp($params['appxml'], 'true') == 0) { 
	$tool_params['align'] = 7; // NCBI BLAST XML
      }
    }
    foreach($content as $data) {
      if($data->type == 'sequence') {
	$tool_params['sequence'] = $data->content;
      }
    }
    $parameters = array(
			'email' => $params['email'],
			'title' => NULL,
			'parameters' => $tool_params
			);
    $this->serviceProxyConnect('runWUBlast');
    $res = $this->srvProxy->run($parameters);
    $jobId = $res->jobId;
    // Simulate synchronous mode and wait for job to complete.
    if(!array_key_exists('async', $params) ||
       !$params['async']) {
      $this->clientPoll($jobId);
    }
    return $jobId;
  }

  // checkStatus(jobid) - get job status
  public function checkStatus($jobid) {
    $jobid = trim($jobid);
    if(strlen($jobid) < 1) {
      return new SoapFault('Server', 'Job identifier not specified');
    }
    // Get service proxy.
    $this->serviceProxyConnect('checkStatus');
    // Get status of WU-BLAST (SOAP) job.
    $res = $this->srvProxy->getStatus(array('jobId' => $jobid));
    $status = $res->status;
    // Convert status into WSWUBlast status code.
    $status_mapping = array(
			    'RUNNING' => 'RUNNING',
			    'FINISHED' => 'DONE',
			    'ERROR' => 'ERROR',
			    'FAILURE' => 'ERROR',
			    'NOT_FOUND' => 'NOT_FOUND'
			    );
    // Return status.
    return $status_mapping[$status];
  }

  // poll(jobid type)
  public function poll($jobid, $type) {
    $jobid = trim($jobid);
    if(strlen($jobid) < 1) {
      return new SoapFault('Server', 'Job identifier not specified');
    }
    // Get service proxy.
    $this->serviceProxyConnect('poll');
    // Wait for job to complete.
    $this->clientPoll($jobid);
    // Get the requested result.
    if($type == 'tooloutput') $type = 'out';
    if($type == 'toolxml') $type = 'xml';
    $res = $this->srvProxy->getResult(array('jobId' => $jobid,
					    'type' => $type,
					    'parameters' => array()
					    )
				      );
    return $res->output;
  }

  // getResults(jobid) - get result types for job.
  public function getResults($jobid) {
    $jobid = trim($jobid);
    if(strlen($jobid) < 1) {
      return new SoapFault('Server', 'Job identifier not specified');
    }
    // Get service proxy.
    $this->serviceProxyConnect('getResults');
    // Get the available result types.
    $res = $this->srvProxy->getResultTypes(array('jobId' => $jobid));
    $resultTypes = $res->resultTypes->type;
    // Map into Dispatcher names.
    $retVal = array();
    foreach($resultTypes as $resultType) {
      $file_ext = $resultType->identifier . '.' . $resultType->fileSuffix;
      if($resultType->identifier == 'out') $file_ext = 'out';
      if($resultType->identifier == 'xml') $file_ext = 'xml';
      $res_type = array(
			'type' => $resultType->identifier,
			'ext' => $file_ext
			);
      $retVal[] = $res_type;
    }
    return $retVal;
  }

  // getIds(jobid)
  public function getIds($jobid) {
    return poll($jobid, 'ids');
  }

  // *** Meta-data operations ***
  // TODO: getDatabases
  public function getDatabases() {
    return new SoapFault('Server', 'Service operation getDatabases() not implemented');
  }

  // TODO: getFilters
  public function getFilters() {
    return new SoapFault('Server', 'Service operation getFilters() not implemented');
  }

  // TODO: getMatrices
  public function getMatrices() {
    return new SoapFault('Server', 'Service operation getMatrices() not implemented');
  }

  // TODO: getPrograms
  public function getPrograms() {
    return new SoapFault('Server', 'Service operation getPrograms() not implemented');
  }

  // TODO: getSensitivity
  public function getSensitivity() {
    return new SoapFault('Server', 'Service operation getSensitivity() not implemented');
  }

  // TODO: getSort
  public function getSort() {
    return new SoapFault('Server', 'Service operation getSort() not implemented');
  }

  // TODO: getStats
  public function getStats() {
    return new SoapFault('Server', 'Service operation getStats() not implemented');
  }

  // TODO: getXmlFormats
  public function getXmlFormats() {
    return new SoapFault('Server', 'Service operation getXmlFormats() not implemented');
  }

  // *** Deprecated operations ***
  // blastp(database, sequence, email)
  public function blastp($database, $sequence, $email) {
    return new SoapFault('Server', 'Services operation blastp(database, sequence, email) not implemented');
    /* $params = array(
                    'email' => $email,
                    'program' => 'blastp',
                    'database' => $database,
                    'async' => FALSE
                   );
    // TODO: package query sequence.
    $content = array();
    return runWUBlast($params, $content); */
  }

  // blastn(database, sequence, email)
  public function blastn($database, $sequence, $email) {
    return new SoapFault('Server', 'Service operation blastn(database, sequence, email) not implemented');
    /* $params = array(
                    'email' => $email,
                    'program' => 'blastn',
                    'database' => $database,
                    'async' => FALSE
                   );
    // TODO: package query sequence.
    $content = array();
    return runWUBlast($params, $content); */
  }

  // getOutput(jobid)
  public function getOutput($jobid) {
    return poll($jobid, 'out');
  }

  // getXML(jobid)
  public function getXML($jobid) {
    return poll($jobid, 'xml');
  }

  // doWUBlast(params, content)
  public function doWUBlast($params, $content) {
    return runWUBlast($params, $content);
  }

  // polljob(jobid, outformat)
  public function polljob($jobid, $type) {
    return poll($jobid, $type);
  }
}

// Initialise the WSWUBlast RPC/encoded SOAP interface.
$WSWUBlast_WSDL = 'WSWUBlast_alt.wsdl';
$server = new SoapServer($WSWUBlast_WSDL);
$server->setClass('WuBlastSoap');
$server->handle();
?>
