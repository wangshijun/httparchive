<?php
/*
Copyright 2010 Google Inc.

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

     http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
*/

require_once("bootstrap.inc");
require_once("../utils.inc");
require_once("../crawls.inc");
require_once("../status.inc");
require_once("batch_lib.inc");

if ( ! tableExists($gStatusTable) ) {
	cprint("Please run batch_start to kick off a new batch!");
	exit();
}



$maxPasses = 2; // We'll submit failed URLs no more than this number of times.
$labelFromRun = statusLabel();
if ( ! $labelFromRun ) {
	// there's no run to finish
	exit();
}
$curPasses = crawlPasses($labelFromRun, $gArchive, $locations[0]);
if ( $curPasses >= $maxPasses ) {
	// Now that we're running as a cronjob, we need to emit nothing to stdout when we're all done.
	exit();
}

if ( 0 === totalNotDone() ) {
	$curPasses++;
	updateCrawl($labelFromRun, $gArchive, $locations[0], array( "passes" => $curPasses ));

	if ( $curPasses < $maxPasses ) {
		resubmitFailures();
		cprint("Resubmitted failures - going around once again...");
	}
	else {
		// We just finished the last pass. Wrap it up...
		cprint(date("G:i") . ": DONE with tests. Copying...");
		$gParamLabel = $labelFromRun; // hack!

		// IMPORTANT: Update crawl info FIRST because many pieces of code reference this:
		$query = "select min(pageid) as minid, max(pageid) as maxid from $gPagesTable where label='$labelFromRun';";
		$row = doRowQuery($query);
		$minid = $row['minid'];
		$maxid = $row['maxid'];
		$numPages = doSimpleQuery("select count(*) from $gPagesTable where pageid >= $minid and pageid <= $maxid;");
		$numRequests = doSimpleQuery("select count(*) from $gRequestsTable where pageid >= $minid and pageid <= $maxid;");
		updateCrawl($labelFromRun, $gArchive, $locations[0], 
					array(
						  "minPageid" => $minid,
						  "maxPageid" => $maxid,
						  "numErrors" => statusErrors(),
						  "numPages" => $numPages,
						  "numRequests" => $numRequests
						  ));

		// Copy rows, calc stats, create dump files, etc.
		require_once("copy.php");

		updateCrawl($labelFromRun, $gArchive, $locations[0], array( "finishedDateTime" => time() ));

		cprint(date("G:i") . ": DONE with crawl!");
		exit(0);
	}
}


// TODO - Combine "obtain" and "parse"?
// The "crawl" process has multiple distinct tasks. This is because the URLs are
// sent to and queued at WebPagetest which is asynchronous.
// We create a child process for each task. 
// Each task has a unique file lock, so that a long task does NOT block a
// shorter process from being restarted during the next cronjob.

$aChildPids = array();
foreach ( $gaTasks as $task ) {
	// lock file for this specific task.
	$lockfile = lockFilename($locations[0], $task);
	$fp = fopen($lockfile, "w+");
	if ( !flock($fp, LOCK_EX | LOCK_NB) ) {
		// this task is still running
		lprint("Task \"$task\" already running. Bail.");
	}
	else {
		// start this task
		lprint("Starting task \"$task\"...");

		// fork the child process
		// return: 
		//   -1 - error
		//    0 - we're the forked child process
		//   >0 - we're the parent process and this is the process ID of the child process
		$pid = pcntl_fork();
		if ( -1 === $pid ) {
			die("ERROR: failed to fork child process.\n");
		}

		if ( $pid ) {
			// parent process - save the child process ID
			$aChildPids[] = $pid;
			tprint("$task pid = $pid");
		} 
		else {
			// child process
			if ( "submit" === $task ) {
				// Submit the jobs to WebPagetest.
				submitBatch();
			} 
			else if ( "status" === $task ) {
				// Check the test status with WPT server
				checkWPTStatus();
			} 
			else if ( "obtain" === $task ) {
				// Obtain XML result
				obtainXMLResults();
			} 
			else if ( 0 === strpos($task, "parse") ) {
				$n = intval(substr($task, strlen("parse")));  // extract the process # from the parse task
				// Fill page table and request table
				fillTables($gNumParse, $n-1);  // use the process # as a way to divvy up the jobs, use n-1 since it has to be zero-based
			}
			flock($fp, LOCK_UN);
			lprint("...DONE with task \"$task\"!");
			exit();
		}
	}
}

// Loop through the processes until all of them are done and then exit.
while ( count($aChildPids) > 0 ) {
	// Call pcntl_waitpid as many times as there are processes before sleeping.
	$numChildren = count($aChildPids);
	for ( $i = 0; $i < $numChildren; $i++ ) {
		$exitPid = pcntl_waitpid(-1, $status, WNOHANG); // catch the child process when it exits
		if ( $exitPid ) {
			foreach ( $aChildPids as $key => $childPid ) {
				if ( $childPid === $exitPid ) {
					unset($aChildPids[$key]);
				}
			}
		}
	}
	if ( count($aChildPids) > 0 ) {
		sleep(10);
	}
}

lprint(reportSummary());

?>
