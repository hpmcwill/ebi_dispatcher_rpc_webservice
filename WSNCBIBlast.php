<?php
  /* EMBL-EBI WSNCBIBlast service proxy for NCBI BLAST (SOAP).
   *
   * For WSNCBIBlast see:
   * http://www.ebi.ac.uk/Tools/webservices/services/archive/sss/wsncbiblast
   * https://www.biocatalogue.org/services/44
   * For NCBI BLAST (SOAP) see:
   * http://www.ebi.ac.uk/Tools/webservices/services/sss/ncbi_blast_soap
   */

  // Back-end class to map WSNCBIBlast requests to NCBI BLAST (SOAP)
class NcbiBlastSoap {
  // Service WSDL
  private $wsdlUrl = 'http://www.ebi.ac.uk/Tools/services/soap/ncbiblast?wsdl';
  // Service proxy.
  private $srvProxy;

  // Get HTTP User-agent string.
  private function getUserAgent($opName = 'unknown') {
    $clientVersion = trim(substr('$Revision: 1 $', 11), ' $');
    if($clientVersion == '') $clientVersion = '0';
    $sysname  = php_uname('s');
    $userAgent = 'WSNCBIBlast/' . $clientVersion . ' (' . get_class($this) . '; ' . $sysname . '; ' . $opName . ') PHP-SOAP/' . phpversion();
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

  // runNCBIBlast(params content)
  public function runNCBIBlast($params, $content) {
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
    if(array_key_exists('matrix', $params)) {
      $tool_params['matrix'] = $params['matrix'];
    }
    if(array_key_exists('numal', $params)) {
      $tool_params['alignments'] = $params['numal'];
    }
    if(array_key_exists('scores', $params)) {
      $tool_params['scores'] = $params['scores'];
    }
    if(array_key_exists('exp', $params)) {
      if($params['exp'] >= 1.0) {
        $tool_params['exp'] = sprintf('%.1f', $params['exp']);
      }
      else {
        $tool_params['exp'] = sprintf('%.0e', $params['exp']);
      }
    }
    if(array_key_exists('filter', $params)) {
      $tool_params['filter'] = $params['filter'];
    }
    if(array_key_exists('opengap', $params)) {
      $tool_params['gapopen'] = $params['opengap'];
    }
    if(array_key_exists('extendgap', $params)) {
      $tool_params['gapext'] = $params['extendgap'];
    }
    if(array_key_exists('dropoff', $params)) {
      $tool_params['dropoff'] = $params['dropoff'];
    }
    if(array_key_exists('gapalign', $params)) {
      $tool_params['gapalign'] = $params['gapalign'];
    }
    if(array_key_exists('match', $params) && !empty($params['match']) &&
       array_key_exists('mismatch', $params) && !empty($params['mismatch'])) {
      $tool_params['match_scores'] = $params['match'] . ',' . $params['mismatch'];
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
    $this->serviceProxyConnect('runNCBIBlast');
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
    // Get status of NCBI BLAST (SOAP) job.
    $res = $this->srvProxy->getStatus(array('jobId' => $jobid));
    $status = $res->status;
    // Convert status into WSNCBIBlast status code.
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

  // test(jobid type)
  public function test($jobid, $type) {
    return poll($jobid, $type);
  }

  // getIds(jobid)
  public function getIds($jobid) {
    return poll($jobid, 'ids');
  }
}

// Initialise the WSNCBIBlast RPC/encoded SOAP interface.
$WSNCBIBlast_WSDL = 'WSNCBIBlast_alt.wsdl';
$server = new SoapServer($WSNCBIBlast_WSDL);
$server->setClass('NcbiBlastSoap');
$server->handle();
?>
