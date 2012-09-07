<?php

require("config.php");


printf("You are about to generate JSON-RPC command that will execute a dividend 
 ... payment of %.8f BTC, among the shareholders of '%s'\n", TOTAL_BTC, ASSET);
echo "Your PGP key fingerprint is: ".ADMIN_PGP."\n\n";
printf('If you agree to proceed, type "Yes" (no quotes) and hit Enter : ');
if (trim(fgets(STDIN))!="Yes") {
	echo("\nYou did not type Yes so the script is going to terminate now.
If any of the above values was incorrect, edit config.php to fix it.\n");
	finito(1);
}

// Import all the PGP keys
echo "\n1) Preparing the verification envirionment...\n";

$archive = "contracts_".ASSET.".tar.gz";

if (!file_exists($archive)) {
	error_log("ERROR: $archive not found in the current directory.");
	finito(1);
}

// Remove the assets folder and the old GnuPG keyrings
@system("rm -rf ".ASSET."/ gnupg/");

// Setup GnuPG envirionment
@mkdir("gnupg/");
putenv("GNUPGHOME=".getcwd()."/gnupg");


// Extract the contracts
@system("tar zxf $archive");
if (!file_exists(ASSET."/keys.asc")) {
	error_log("ERROR: $archive does not seem to be consistent.");
	finito(1);
}

// Import all the PGP keys
echo "\n2) Importing all your customers PGP keys...\n";
@system("gpg --import ".ASSET."/keys.asc");
@unlink(ASSET."/keys.asc");

// Process all the contracts one-by-one
echo "\n3) Processing the contracts...\n";
$prvseq = -1;
$balance = array();
$divaddr = array();
@include('divadr.php');
$contracts = scandir(ASSET.'/');
foreach($contracts as $c) {
	$tim = 0;
	$tid = 0;
	$n = sscanf($c, "%u-%u.asc", $tim, $tid);
	if ($n==2) {
		printf("%-23s from %s ", $c, date('Y-m-d,H:i', $tim));
		$output = shell_exec("gpg -v --fingerprint -d ".ASSET."/$c 2>&1 > contract.txt");
		if (!file_exists("contract.txt")) {
			echo " - ERROR!\n";
			error_log("ERROR: one of the contracts inside the $archive has not been decoded.");
			finito(1);
		}
		$signed = getfp($output);
		if ($signed=='') {
			echo " - BAD SIGNATURE!\n$output\n";
			error_log("ERROR: one of the contracts inside the $archive is not signed properly.");
			finito(1);
		}
		echo " signed ".substr($signed,0,16)."...\n";
		$er = execute_contract(file_get_contents("contract.txt"));
		unlink("contract.txt");
		if ($er!==FALSE) {
			error_log("ERROR: $er");
			finito(1);
		}
	}
}


echo "\n4) Checking the dividend addresses...\n";
$total_shares = 0;
foreach(array_keys($balance) as $fp) {
	if (!array_key_exists($fp, $divaddr)) {
		error_log("ERROR: $fp does not have dividend payment address");
		finito(1);
	}
	$total_shares += $balance[$fp];
}


echo "\n4) Generating the JSON-RPC command...\n";
$rpc = 'sendmany "" \'{';
$com = false;
foreach(array_keys($balance) as $fp) {
	if ($com)  $rpc .= ', ';
	$am = $balance[$fp]*TOTAL_BTC/$total_shares;
	$rpc .= '"'.$divaddr[$fp].'":'.sprintf('%.8f',$am);
	$com = true;
}
$rpc .= '}\'';

printf("%.8f BTC to pay for %u shares -> %.8f BTC / share\n", TOTAL_BTC, $total_shares, TOTAL_BTC/$total_shares);

$fn = ASSET."_div_pay_".time().".rpc";
file_put_contents($fn, $rpc);
echo "The RPC commmand has been placed in file: $fn\n";


finito(0);


////////////////////////////////////////////////////////////////////////////////////////////////////////////


function finito($code) {
	system("rm -rf ./".ASSET."/ gnupg/");
	exit($code);
}


function getfp($s) {
	$sigok = false;
	$fp = '';
	$ls = split("\n", $s);
	for ($i=0; $i<count($ls); $i++) {
		if (substr($ls[$i], 0, 19)=='gpg: Good signature') {
			$sigok = true;
		} else if (substr($ls[$i],0,24)=="Primary key fingerprint:") {
			$fingerprint = strtoupper(str_replace(' ', '', trim(substr($ls[$i], 24))));
			break;
		}

	}
	return $sigok ? $fingerprint : '';
}

function execute_contract($s) {
	$ls = split("\n", $s);
	if (substr($ls[0],0,9)!="Warning: ") {
		return 'This doesn\'t seem to look like a contract';
	}
	for ($i=1; $i<count($ls); $i++) {
		$li = split(':', $ls[$i], 2);
		if (count($li)==2) {
			$o[$li[0]] = trim($li[1]);
		}
	}

	switch ($o['ORDER']) {
		case 'TRANSFER': return process_transfer($o);
		case 'DIVADR': return process_divadr($o);
		default: return 'Unknown order '.$o['ORDER'];
	}

	return 'Some error';
}

function process_divadr($o) {
	global $signed, $divaddr;

	if ($signed!=$o['USER']) {
		return "DIVADR must be signed by the asset owner";
	}

	if ($o['ASSET']!=ASSET) {
		return $o['ASSET']." is not the right asset";
	}

	$divaddr[$o['USER']] = $o['ADDRESS'];

	return FALSE;
}


function process_transfer($o) {
	global $signed, $prvseq, $balance;
	if ($signed!=ADMIN_PGP) {
		return "TRANSFER must be signed by the asset issuer";
	}

	if ($o['ASSET']!=ASSET) {
		return $o['ASSET']." is not the right asset";
	}
	$cnt = intval($o['COUNT']);
	if ($cnt<=0) {
		return "Incorrect number of assets in the contract $cnt";
	}

	$seq = intval($o['SEQ']);
	if ($seq<=$prvseq) {
		return "TRANSFER sequence goes out of order";
	}
	$prvseq = $seq;

	$fr = $o['FROM'];
	$to = $o['TO'];
	
	if ($to!='CANCEL_THE_ASSETS') {
		if (array_key_exists($to, $balance)) {
			$balance[$to] += $cnt;
		} else {
			$balance[$to] = $cnt;
		}
	}
	if ($fr!='CREATE_NEW_ASSETS') {
		if ($balance[$fr]<$cnt) {
			return "Not enough assets at the source account";
		}
		$balance[$fr] -= $cnt;
	}

	return FALSE;
}

?>
