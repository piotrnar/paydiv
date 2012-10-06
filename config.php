<?php
//	Before you run paydiv.php you need to set the 3 values below, accordingly.

// The name of your asset at #assets-otc:
define('ASSET', 'ASSETS-OTC');

// The fingerprint of your PGP key (use upper case letters):
define('ADMIN_PGP', "AB9EA551E262A87A13BB90591BE7B545CDF3FD0E");

// The total amount of dividends that you are about to pay out (in BTC):
define('TOTAL_BTC', 0.0);

// Account in your wallet from which the funds shall be sent
define('ACCOUNT', "");

?>