<?php
namespace Vanderbilt\VoteCap;

class VoteCap extends \ExternalModules\AbstractExternalModule
{
	private $project_id;
	private $Proj;
	private $session;
	private $sessions;
	private $questions;
	private $cookie_name = 'votecap';
	private $field_check = array('session_id', 'session_name', 'question', 'answer', 'votes', 'session_expiration');
	
	// Constructor
	public function __construct($project_id=null)
	{
		parent::__construct();
		// Set project_id for this object
		if ($project_id !== null && defined("PROJECT_ID")) {
			$this->project_id = $project_id;
			$this->Proj = new \Project($this->project_id);
		} else {
			return;
		}
		// Make sure all necessary fields exist in project
		$this->checkNecessaryFields();
		// Load the cookie and its values
		$this->loadCookie();
		// If a POST request, then process the params
		// Submit a new question
		if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_GET['sid']) && is_numeric($_GET['sid']) && isset($_POST['newquestion'])) {
			$this->submitQuestion($_GET['sid'], trim($_POST['newquestion']));
			redirect($_SERVER['REQUEST_URI']."&msg=new");
		}
		// Save a vote submitted
		elseif ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_GET['sid']) && is_numeric($_GET['sid'])) {
			$this->saveVote($_POST['qid'], $_POST['value']);
		}		
		// Display all the questions for a specific session
		elseif ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['sid']) && is_numeric($_GET['sid'])) {
			$this->loadSessions($_GET['sid']);
			$this->loadQuestions($_GET['sid']);
			$this->renderQuestions();
		}
		// Display a list of all sessions
		else {
			$this->loadSessions();
			$this->renderSessions();
		}
	}
	
	private function checkNecessaryFields($record=null, $question=null)
	{
		// Make sure all necessary fields exist in project
		$fieldsMissing = array();
		foreach ($this->field_check as $field) {
			if (!isset($this->Proj->metadata[$field])) {
				$fieldsMissing[] = $field;
			}
		}
		if (!empty($fieldsMissing)) {
			exit("ERROR: The following necessary fields are missing from the project: ".prep_implode($fieldsMissing).".");
		}
	}
	
	private function submitQuestion($record=null, $question=null)
	{
		// Get the highest numbered question so far
		$numQuestions = 0;
		$data = \REDCap::getData('array', $_GET['sid'], array('session_id', 'question'));
		foreach ($data[$record] as $event_id=>$attr) {
			if ($event_id != 'repeat_instances') continue;
			foreach ($attr as $event_id=>$bttr) {
				foreach ($bttr as $repeat_instrument=>$cttr) {
					$numQuestions = max(array_keys($cttr));
				}
			}
		}
		$newQuestionNum = $numQuestions+1;
		// Save new vote value
		$array_data = array(array(
			'session_id' => $_GET['sid'],
            'redcap_repeat_instrument' => $this->Proj->metadata['votes']['form_name'],
            'redcap_repeat_instance' => $newQuestionNum,
			'question' => $question,
			'votes' => '0'
		));
		$response = \REDCap::saveData('json', json_encode($array_data));
		if (!empty($response['errors'])) exit("ERROR: ".$response['errors']);
	}
	
	private function saveVote($qid=null, $value=1)
	{
		if (!is_numeric($qid) || abs($value) !== 1) exit;
		$votes = $this->saveVoteDb($qid, $value);
		$this->saveVoteCookie($qid, $value);
		// Return new vote count on success
		print $votes;
	}
	
	private function saveVoteCookie($qid=null, $value=1)
	{
		if (!is_numeric($qid) || abs($value) !== 1) exit;
		if ($value > 0) {
			$this->saveCookieStateValue($_GET['pid']."-".$_GET['sid'], $qid, $value);
		} else {
			$this->removeCookieStateValue($_GET['pid']."-".$_GET['sid'], $qid);
		}
	}
	
	private function saveVoteDb($qid=null, $value=1)
	{
		if (!is_numeric($qid) || abs($value) !== 1) exit;
		// Get current vote value
		$votes = null;
		$data = \REDCap::getData('json', $_GET['sid'], array('session_id', 'votes', $this->Proj->metadata['votes']['form_name'].'_complete'));
		foreach (json_decode($data, true) as $attr) {
			if ($attr['redcap_repeat_instance'] != $qid) continue;
			if ($attr['votes'] == '') $attr['votes'] = 0;
			$votes = $attr['votes'];
		}
		if ($votes === null) exit;
		// Set new value
		$newvotes = $votes + $value;
		// Save new vote value
		$array_data = array(array(
			'session_id' => $_GET['sid'],
            'redcap_repeat_instrument' => $this->Proj->metadata['votes']['form_name'],
            'redcap_repeat_instance' => $qid,
            'votes' => $newvotes
		));
		$response = \REDCap::saveData('json', json_encode($array_data));
		if (!empty($response['errors'])) exit;
		// Return new vote count
		return $newvotes;
	}
	
	private function loadSessions($record=array())
	{
		$filterLogic = "[session_expiration] = '' or [session_expiration] > '" . date('Y-m-d H:i') . "'";
		$data = \REDCap::getData('array', $record, 'session_name', array(), array(), false, false, false, $filterLogic);
		foreach ($data as $record=>$attr) {
			foreach ($attr as $event_id=>$bttr) {
				$this->sessions[$record] = $bttr['session_name'];
				$this->session = $bttr['session_name'];
			}
		}
	}
	
	private function loadQuestions($record=null)
	{
		$data = \REDCap::getData('array', $record, array('question', 'votes', 'answer'));
		foreach ($data as $record=>$attr) {
			foreach ($attr as $event_id=>$bttr) {
				if ($event_id != 'repeat_instances') continue;
				foreach ($bttr as $event_id=>$cttr) {
					foreach ($cttr as $repeat_instrument=>$dttr) {
						foreach ($dttr as $repeat_instance=>$ettr) {
							$this->questions[$repeat_instance]['id'] = $repeat_instance;
							$this->questions[$repeat_instance]['q'] = trim($ettr['question']);
							$this->questions[$repeat_instance]['a'] = trim($ettr['answer']);
							$this->questions[$repeat_instance]['c'] = ($ettr['votes'] == '') ? '0' : $ettr['votes'];
							// Is the question already upvoted?
							$upvoted = $this->getCookieStateValue($_GET['pid']."-".$record, $repeat_instance);
							$this->questions[$repeat_instance]['v'] = ($upvoted === null) ? 'notvoted' : 'voted';
						}
					}
				}
			}
		}
		// Now order by them count in descending order. Also place all answered questions at end (also ordered by count).
		$this->orderQuestionsByCount();
	}
	
	private function orderQuestionsByCount()
	{
		$count_array = array();
		foreach ($this->questions as $qnum=>$attr) {
			$count_array[$qnum] = $attr['c'];
			$answer_array[$qnum] = ($attr['a'] == '') ? 0 : 1;
		}
		array_multisort($answer_array, SORT_NUMERIC, $count_array, SORT_NUMERIC, SORT_DESC, $this->questions);
	}
	
	private function renderSessions()
	{
		$HtmlPage = new \HtmlPage();
		$HtmlPage->PrintHeaderExt();
		$instructions = $this->getProjectSetting('instructions', PROJECT_ID);
		if (trim($instructions) != '') {
			?><div class="panel panel-default card mb-3">
				<div class="panel-heading card-header" style="font-size:14px;"><?=$instructions?></div>
			</div><?php
		} ?>
		<div class="panel panel-default card">
			<div class="panel-heading card-header" style="font-size:28px;"><?=$this->getProjectSetting('title', PROJECT_ID)?></div>
			<?php if (empty($this->sessions)) { ?>
				<ul class="list-group">
					<li class="list-group-item" style="color:#A00000;">
						<span class="glyphicon glyphicon-info-sign" aria-hidden="true"></span>
						No listings have been created yet. They will first need to be created as individual
						records in the REDCap project before being displayed here.
					</li>
				</ul>
			<?php } else { ?>
				<!-- List group -->
				<ul class="list-group">
					<?php foreach ($this->sessions as $id=>$name) { ?>
					<li class="list-group-item"><a style="font-size:16px;" href="<?php print $_SERVER['REQUEST_URI']."&sid={$id}" 
						?>"><?php print htmlspecialchars($name, ENT_QUOTES) ?></a></li>
					<?php } ?>
				</ul>
			<?php } ?>
		</div>
		<?php
		$HtmlPage->PrintFooterExt();
	}
	
	private function renderQuestions()
	{
		$lastAnswer = null;
		$HtmlPage = new \HtmlPage();
		$HtmlPage->PrintHeaderExt();
		?>
		<link rel="stylesheet" type="text/css" media="screen,print" href="<?php print $this->getUrl("assets/votecap.css") ?>"/>
		<script type="text/javascript" src="<?php print $this->getUrl("assets/votecap.js") ?>"></script>
		
		<div class="pull-right float-right"><a style="text-decoration:underline;font-size:14px;" href="<?php print PAGE_FULL."?NOAUTH&pid={$this->project_id}&page={$_GET['page']}&prefix={$_GET['prefix']}" ?>">Return to previous page</a></div>
		<div class="pull-right float-right" style="margin-right:25px;color:#bbb;font-size:12px;">Page refreshes every 30 seconds</div>
		<div class="clear"></div>
		<h1 style="margin-top:5px;"><?php print htmlspecialchars($this->session, ENT_QUOTES) ?></h1>
		
		<?php if (isset($_GET['msg']) && $_GET['msg'] == 'new') { ?>
		<div class="alert alert-success" style="border:1px solid #d6e9c6 !important;font-size:16px;">
			<strong>Success!</strong> Your submission was added at the bottom of the page.
		</div>
		<?php } ?>
		
		<div class="panel panel-default card">
			<!-- Default panel contents -->
			<div class="panel-heading card-header">
				<div class="row">
					<form method="post" action="<?php print $_SERVER['REQUEST_URI'] ?>" id="newquestion_form" style="width:100%;">
						<div class="col-lg-9">
							<div class="input-group">
								<input type="text" tabindex="1" id="newquestion" name="newquestion" class="form-control" placeholder="<?=htmlspecialchars($this->getProjectSetting('ask-question-placeholder', PROJECT_ID), ENT_QUOTES)?>">
								<span class="input-group-btn">
									<button tabindex="2" id="newquestion_submit" class="btn btn-defaultrc" type="button"><b>Submit</b></button>
								</span>
							</div>
						</div>
					</form>
				</div>
			</div>
			<!-- Questions -->
			<ul class="list-group">
				<?php foreach ($this->questions as $attr) 
				{
					if ($lastAnswer !== null && $lastAnswer == '' && $attr['a'] != '') {
						?>
						<li class="list-group-item" style="background-color: #f5f5f5;border-color: #ddd;padding-top:25px;">
							<div style="font-weight:bold;font-size:16px;color:#000;">
								Answered Questions
							</div>
						</li>
						<?php
					}
					?>
					<li class="list-group-item">
						<div qid="<?php print $attr['id'] ?>" class="<?php print $attr['v'] ?> votebox pull-left float-left text-center" style="padding:0px 30px 2px 2px;width:100px;">
							<i class="fas fa-thumbs-up" style="font-size:36px;margin-bottom:5px;" aria-hidden="true" title="Click to vote or unvote"></i><br>
							<span id="vc_<?php print $attr['id'] ?>" class="nowrap" style="font-size:14px;font-weight:bold;">
								<?php print $attr['c'] ?> vote<?php if ($attr['c'] != 1) print "s"; ?>
							</span>
						</div>
						<div class="clearfix" style="font-size:16px;">
							<?php print htmlspecialchars($attr['q'], ENT_QUOTES) ?>
						</div>
						<?php if ($attr['a'] != '') { ?>
							<div class="clearfix" style="margin-left:100px;font-size:15px;color:#0021cc;">
								<i>Answer:</i> <?php print htmlspecialchars($attr['a'], ENT_QUOTES) ?>
							</div>
						<?php } ?>
					</li><?php 
					$lastAnswer = $attr['a'];
				} 
				?>
			</ul>
		</div>
		<?php
		$HtmlPage->PrintFooterExt();
	}
	
	
	// Return a value from the UI state config. Return null if key doesn't exist. (e.g., $object = 'sidebar')
	public function getCookieStateValue($key, $subkey)
	{
		// Return value if exists, else return null.
		return (isset($_COOKIE[$this->cookie_name][$key][$subkey]) ? $_COOKIE[$this->cookie_name][$key][$subkey] : null);
	}
	
	// Save a value in the UI state config (e.g., $object = 'sidebar')
	public function saveCookieStateValue($key, $subkey, $value)
	{
		// Add value to array
		$_COOKIE[$this->cookie_name][$key][$subkey] = $value;
		// Save state with desired expiration
		$this->saveCookieState();
	}
	
	// Remove key-value from the UI state config
	public function removeCookieStateValue($key, $subkey)
	{
		// Remove value
		unset($_COOKIE[$this->cookie_name][$key][$subkey]);
		// Save state with desired expiration
		$this->saveCookieState();
	}
	
	// Save the UI state by passing the array of values
	private function saveCookieState()
	{
		$_COOKIE[$this->cookie_name] = (empty($_COOKIE[$this->cookie_name]) || !is_array($_COOKIE[$this->cookie_name])) 
										? "" : serialize($_COOKIE[$this->cookie_name]);
		$cookie_params = session_get_cookie_params();
		setcookie($this->cookie_name, $_COOKIE[$this->cookie_name], time()+(3600*24*365), '/', '', ($cookie_params['secure']===true), true);
	}
	
	private function loadCookie() 
	{
		if (isset($_COOKIE[$this->cookie_name])) {
			if (!is_array($_COOKIE[$this->cookie_name])) {
				$_COOKIE[$this->cookie_name] = unserialize($_COOKIE[$this->cookie_name]);
			}
			if (!is_array($_COOKIE[$this->cookie_name])) {
				$_COOKIE[$this->cookie_name] = array();
			}
		} else {
			$_COOKIE[$this->cookie_name] = array();
		}
	}

	public function redcap_module_link_check_display()
	{
		return true;
	}
}
