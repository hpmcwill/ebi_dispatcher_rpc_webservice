<?php
  /* EMBL-EBI WSInterProScan service proxy for InterProScan (SOAP).
   *
   * For WSInterProScan see:
   * http://www.ebi.ac.uk/Tools/webservices/services/archive/pfa/wsinterproscan
   * https://www.biocatalogue.org/services/5
   * For InterProScan 4.x (SOAP) see:
   * http://www.ebi.ac.uk/Tools/webservices/services/pfa/iprscan_soap
   */

  // Back-end class to map WSInterProScan requests to InterProScan (SOAP)
class InterProScanSoap {
  // Service WSDL
  private $wsdlUrl = 'http://www.ebi.ac.uk/Tools/services/soap/iprscan?wsdl';
  // Service proxy.
  private $srvProxy;

  // Get HTTP User-agent string.
  private function getUserAgent($opName = 'unknown') {
    $clientVersion = trim(substr('$Revision: 1 $', 11), ' $');
    if($clientVersion == '') $clientVersion = '0';
    $sysname  = php_uname('s');
    $userAgent = 'WSInterProScan/' . $clientVersion . ' (' . get_class($this) . '; ' . $sysname . '; ' . $opName . ') PHP-SOAP/' . phpversion();
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

  // runInterProScan(params content)
  public function runInterProScan($params, $content) {
    // Map parameters
    $tool_params = array();
    if(array_key_exists('app', $params) && strcasecmp($params['app'], 'all') != 0) {
      $tool_params['appl'] = explode(' ', $params['app']);
    }
    if(array_key_exists('crc', $params)) {
      if($params['crc']) {
        $tool_params['nocrc'] = FALSE;
      }
      else {
        $tool_params['nocrc'] = TRUE;
      }
    }
    if(array_key_exists('goterms', $params)) {
      $tool_params['goterms'] = $params['goterms'];
    }
    if(array_key_exists('seqtype', $params)) {
      if(strcasecmp($params['seqtype'], 'P') != 0) {
        return new SoapFault('Server', 'Invalid value for parameter seqtype');
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
    $this->serviceProxyConnect('runInterProScan');
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
    // Get status of job.
    $res = $this->srvProxy->getStatus(array('jobId' => $jobid));
    $status = $res->status;
    // Convert status into Dispatcher status code.
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

  // *** Meta-data operations ***
  // getAppNames()
  public function getAppNames() {
    // Get service proxy.
    $this->serviceProxyConnect('getAppNames');
    // Get the 'appl' parameter info.
    $res = $this->srvProxy->getParameterDetails(array('parameterId' => 'appl'));
    $paramDetail = $res->parameterDetails;
    $outdataArray = array();
    if(isset($paramDetail->values)) {
      foreach($paramDetail->values->value as $val) {
        $name = $val->value;
        $print_name = $val->value;
        $selected = 'no';
	if($val->defaultValue) $selected = 'yes';
	if($val->label) $print_name = $val->label;
        $outdataArray[] = array('name' => $name, 'print_name' => $print_name, 'selected' => $selected);
      }
    }
    return $outdataArray;
  }

  // *** Deprecated operations ***
  // doIprscan(params, content)
  public function doIprscan($params, $content) {
    return runInterProScan($params, $content);
  }

  // polljob(jobid, outformat)
  public function polljob($jobid, $type) {
    return poll($jobid, $type);
  }
}

// Initialise the InterProScan RPC/encoded SOAP interface.
$WSInterProScan_WSDL = 'WSInterProScan_alt.wsdl';
$server = new SoapServer($WSInterProScan_WSDL);
$server->setClass('InterProScanSoap');
$server->handle();
?>
