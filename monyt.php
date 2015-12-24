<?php
/**
 * Monyt server script
 * returns linux system information to display in your app
 * @see monyt.net
 * @author Chema Garrido <chema@garridodiaz.com>
 * @license GPL v3
 */
error_reporting(0);

if (version_compare(PHP_VERSION, '5.3', '<='))
    die('You need PHP 5.3 or newer to run Monyt server script.');

//////////////// BEGIN OF DEFAULT CONFIG AREA REVIEW ////////////////

// Use authentication - best choice only works with Monyt PRO
// If set to FALSE no authentication
// If set to TRUE  You need to change ADMIN_PASSWORD to make this work!
define('USE_AUTHENTICATION', TRUE);        
define('ADMIN_USERNAME','monyt');
define('ADMIN_PASSWORD','password');     //  CHANGE THIS TO ENABLE AUTHENTICATION!!!


//////////////// DO NOT MODIFY FROM HERE ///////////////////

define('MONYT_SCRIPT_VERSION', '2.0.0 Beta' );


// authentication needed?
if ( USE_AUTHENTICATION === TRUE AND ADMIN_PASSWORD != 'password' AND 
   ( !isset($_SERVER['PHP_AUTH_USER']) OR !isset($_SERVER['PHP_AUTH_PW']) OR
    $_SERVER['PHP_AUTH_USER'] != ADMIN_USERNAME OR $_SERVER['PHP_AUTH_PW'] != ADMIN_PASSWORD ))
{
        header("WWW-Authenticate: Basic realm=\"Monyt Login\"");
        header("HTTP/1.0 401 Unauthorized");

        die('<html><body>
            <h1>Auth needed!</h1>
            <h2>Wrong Username or Password!</h2>
            </body></html>');
}
// end auth

//only retunrs the monyt version
if( isset($_GET['version'] ) )
    $return =  MONYT_SCRIPT_VERSION;
//returns information about the server
elseif( isset($_GET['check']) )
    $return = server_info();
//monitoring status
else
    $return = server_status();

//debug variable
if( isset($_GET['debug']) )
{
    die(print_r($return,1));
}
else
{
    //output json information
    header('Content-type: text/json');
    header('Content-type: application/json');
    die(json_encode($return)) ;
}


/**
 * get server status
 * @return array 
 */
function server_status()
{
    $aStats['monyt']  = MONYT_SCRIPT_VERSION;

    $aStats['uptime'] = trim( file_get_contents("/proc/uptime") );

    //processor usage
    $load = file_get_contents("/proc/loadavg");
    $load = explode( ' ', $load );

    $aStats['load'] = $load[0].', '.$load[1].', '.$load[2];

    //memory info
    $memory = file( '/proc/meminfo' );
    foreach( $memory as $line )
    {
        $line = trim($line);
        
        if( preg_match( '/^memtotal[^\d]+(\d+)[^\d]+$/i', $line, $m ) )
        {
            $aStats['total_memory'] = $m[1];
        }
        else if( preg_match( '/^memfree[^\d]+(\d+)[^\d]+$/i', $line, $m ) )
        {
            $aStats['free_memory'] = $m[1];
        }
    }

    //har disks info
    $aStats['hd'] = array();

    foreach( file('/proc/mounts') as $mount )
    {
        $mount = trim($mount);
        if( $mount && $mount[0] == '/' )
        {
            $parts = explode( ' ', $mount );
            if( $parts[0] != $parts[1] )
            {
                $device = $parts[0];
                $folder = $parts[1];
                $total  = disk_total_space($folder) / 1024;
                $free   = disk_free_space($folder) / 1024;

                if( $total > 0 )
                {
                    $used   = $total - $free;
                    $used_perc = ( $used * 100.0 ) / $total;

                    $aStats['hd'][] = array
                    (
                        'dev' => $device,
                        'total' => $total,
                        'used' => $used,
                        'free' => $free,
                        'used_perc' => $used_perc,
                        'mount' => $folder
                    );
                }
            }
        }
    }

    //networks info
    $ifname = NULL;

    if( file_exists('/etc/network/interfaces') )
    {
        foreach( file('/etc/network/interfaces') as $line )
        {
            $line = trim($line);

            if( preg_match( '/^iface\s+([^\s]+)\s+inet\s+.+$/', $line, $m ) && $m[1] != 'lo' )
            {
                $ifname = $m[1];
                break;
            }
        }
    }
    else
    {
        foreach( glob('/sys/class/net/*') as $filename )
        {
            if( $filename != '/sys/class/net/lo' && file_exists( "$filename/statistics/rx_bytes" ) && trim( file_get_contents("$filename/statistics/rx_bytes") ) != '0' )
            {
                $parts = explode( '/', $filename );
                $ifname = array_pop( $parts );
            }
        }
    }

    if( $ifname != NULL )
    {
        $aStats['net_rx'] = trim( file_get_contents("/sys/class/net/$ifname/statistics/rx_bytes") );
        $aStats['net_tx'] = trim( file_get_contents("/sys/class/net/$ifname/statistics/tx_bytes") );
    }
    else
    {
        $aStats['net_rx'] = 0;
        $aStats['net_tx'] = 0;
    }

    return $aStats;
}


/**
 * server information
 * @return array 
 */
function server_info()
{
    $aCheck = array
    (
        'monyt'     => MONYT_SCRIPT_VERSION,
        'distro'    => '',
        'kernel'    => '',
        'cpu'       => '',
        'cores'     => ''
    );
    
    ///// Get distro name and verion /////
    $sDistroName = '';
    $sDistroVer  = '';

    foreach (glob("/etc/*_version") as $filename) 
    {
        list( $sDistroName, $dummy ) = explode( '_', basename($filename) );

        $sDistroName = ucfirst($sDistroName);
        $sDistroVer  = trim( file_get_contents($filename) );
        
        $aCheck['distro'] = "$sDistroName $sDistroVer";
        break;
    }
    
    if( !$aCheck['distro'] )
    {
        if( file_exists( '/etc/issue' ) )
        {
            $lines = file('/etc/issue');
            $aCheck['distro'] = trim( $lines[0] );
        }
        else
        {
            $output = NULL;
            exec( "uname -om", $output );
            $aCheck['distro'] = trim( implode( ' ', $output ) );
        }
    }
    
    ///// Get CPU Information /////
    $cpu = file( '/proc/cpuinfo' );
    $vendor = NULL;
    $model = NULL;
    $cores = 0;
    foreach( $cpu as $line )
    {
        if( preg_match( '/^vendor_id\s*:\s*(.+)$/i', $line, $m ) )
            $vendor = $m[1];
        elseif( preg_match( '/^model\s+name\s*:\s*(.+)$/i', $line, $m ) )
            $model = $m[1];
        elseif( preg_match( '/^processor\s*:\s*\d+$/i', $line ) )
            $cores++;
    }
    
    $aCheck['cpu']    = "$vendor, $model";
    $aCheck['cores']  = $cores;
    $aCheck['kernel'] = trim(file_get_contents("/proc/version"));

    return $aCheck;
}
?>